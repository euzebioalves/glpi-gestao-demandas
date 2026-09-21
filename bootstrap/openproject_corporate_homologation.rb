# frozen_string_literal: true

require "json"

catalog_path = "/opt/demandas-bootstrap/openproject_homologation.json"
catalog = JSON.parse(File.read(catalog_path))
test_user_password = ENV.fetch("MVP_OPENPROJECT_TEST_USER_PASSWORD")
result = { application: "OpenProject corporate homologation", created: [], updated: [], reused: [], warnings: [] }

def find_named(scope, name)
  scope.where("LOWER(name) = ?", name.downcase).first
end

def assign_if_supported(record, attribute, value)
  writer = "#{attribute}="
  return false unless record.respond_to?(writer)

  current = record.public_send(attribute) if record.respond_to?(attribute)
  return false if current == value

  record.public_send(writer, value)
  true
end

def resolve_custom_field_format(semantic_format)
  aliases = {
    "integer" => %w[int integer],
    "link" => %w[link string],
    "string" => %w[string],
    "float" => %w[float],
    "list" => %w[list],
    "bool" => %w[bool],
    "date" => %w[date]
  }
  candidates = aliases.fetch(semantic_format.to_s, [semantic_format.to_s])
  resolved = candidates.find do |candidate|
    OpenProject::CustomFieldFormat.find_by(name: candidate)
  end
  return resolved if resolved

  raise "Formato de campo customizado não suportado: #{semantic_format} (tentativas: #{candidates.join(', ')})"
end

def synchronize_list_values(field, values)
  return false if values.nil?

  normalized = values.map(&:to_s)
  if field.respond_to?(:possible_values=)
    current = Array(field.possible_values).map(&:to_s)
    return false if current == normalized

    field.possible_values = normalized
    return true
  end

  false
end

ActiveRecord::Base.transaction do
  project = Project.find_by!(identifier: catalog.fetch("project_identifier"))
  required_project_modules = ["work_package_tracking", "costs"]
  current_project_modules = Array(project.enabled_module_names).map(&:to_s)
  missing_project_modules = required_project_modules - current_project_modules
  if missing_project_modules.any?
    project.enabled_module_names = current_project_modules | required_project_modules
    project.save!
    result[:updated] << "project_modules:#{missing_project_modules.join(',')}"
  else
    result[:reused] << "project_modules:ok"
  end

  types = catalog.fetch("types").map do |name|
    find_named(Type.all, name) || raise("Tipo de Work Package não encontrado: #{name}")
  end

  # Mantém as quatro prioridades corporativas e torna Normal o padrão, sem
  # excluir prioridades adicionais que possam existir na instalação.
  if defined?(IssuePriority)
    catalog.fetch("priorities").each do |definition|
      priority = find_named(IssuePriority.all, definition.fetch("name"))
      was_new = priority.nil?
      priority ||= IssuePriority.new
      changed = false
      changed |= assign_if_supported(priority, :name, definition.fetch("name"))
      changed |= assign_if_supported(priority, :position, definition.fetch("position"))
      changed |= assign_if_supported(priority, :is_default, definition.fetch("default"))
      changed |= assign_if_supported(priority, :active, true)
      priority.save! if was_new || changed
      result[was_new ? :created : (changed ? :updated : :reused)] << "priority:#{priority.id}:#{priority.name}"
    end
  else
    result[:warnings] << "IssuePriority não está disponível nesta versão do OpenProject."
  end

  fields = catalog.fetch("custom_fields").map do |definition|
    name = definition.fetch("name")
    field = find_named(WorkPackageCustomField.all, name)
    was_new = field.nil?
    field ||= WorkPackageCustomField.new
    changed = false
    changed |= assign_if_supported(field, :name, name)
    changed |= assign_if_supported(field, :field_format, resolve_custom_field_format(definition.fetch("format"))) if was_new
    changed |= assign_if_supported(field, :is_required, definition.fetch("required", false))
    changed |= assign_if_supported(field, :is_for_all, false)
    changed |= assign_if_supported(field, :visible, true)
    changed |= assign_if_supported(field, :searchable, true)
    values = definition["values_from"] == "customers" ? catalog.fetch("customers") : definition["values"]
    changed |= synchronize_list_values(field, values)
    field.save! if was_new || changed

    unless field.projects.exists?(project.id)
      field.projects << project
      changed = true
    end
    types.each do |type|
      unless field.types.exists?(type.id)
        field.types << type
        changed = true
      end
    end
    result[was_new ? :created : (changed ? :updated : :reused)] << "custom_field:#{field.id}:#{name}"
    field
  end

  fields_by_name = fields.to_h { |field| [field.name.downcase, field] }
  {
    "Ticket GLPI" => "ServiceDesk",
    "URL do Ticket GLPI" => "Link ServiceDesk"
  }.each do |legacy_name, corporate_name|
    legacy_field = find_named(WorkPackageCustomField.all, legacy_name)
    corporate_field = fields_by_name[corporate_name.downcase]
    next unless legacy_field && corporate_field

    work_package_ids = WorkPackage.where(project_id: project.id).select(:id)
    CustomValue.where(
      customized_type: "WorkPackage",
      customized_id: work_package_ids,
      custom_field_id: legacy_field.id
    ).find_each do |legacy_value|
      next if legacy_value.value.to_s.empty?

      corporate_value = CustomValue.find_or_initialize_by(
        customized_type: "WorkPackage",
        customized_id: legacy_value.customized_id,
        custom_field_id: corporate_field.id
      )
      if corporate_value.value.to_s.empty?
        corporate_value.value = legacy_value.value
        corporate_value.save!
        result[corporate_value.previously_new_record? ? :created : :updated] <<
          "custom_value_migrated:#{legacy_value.customized_id}:#{legacy_name}:#{corporate_name}"
      else
        result[:reused] << "custom_value_present:#{legacy_value.customized_id}:#{corporate_name}"
      end
    end
  end

  # Campos usados nas primeiras versões do MVP são mantidos no banco para não
  # apagar o histórico de WPs existentes, mas deixam de compor o formulário de
  # homologação. Os equivalentes corporativos são ServiceDesk e Link ServiceDesk.
  ["Ticket GLPI", "URL do Ticket GLPI"].each do |legacy_name|
    legacy_field = find_named(WorkPackageCustomField.all, legacy_name)
    next unless legacy_field

    changed = false
    if legacy_field.projects.exists?(project.id)
      legacy_field.projects.delete(project)
      changed = true
    end
    types.each do |type|
      if legacy_field.types.exists?(type.id)
        legacy_field.types.delete(type)
        changed = true
      end
    end
    result[changed ? :updated : :reused] << "legacy_custom_field_detached:#{legacy_field.id}:#{legacy_name}"
  end

  required_permissions = %i[
    view_project
    view_work_packages
    add_work_packages
    edit_work_packages
    add_work_package_notes
    manage_work_package_relations
    view_members
    view_time_entries
    log_own_time
  ]
  role_name = "Equipe de Homologação — Demandas"
  role = find_named(Role.where(builtin: 0), role_name)
  role_was_new = role.nil?
  assignable_source_role = Role.where(builtin: 0, assignable: true).first if Role.column_names.include?("assignable")
  unless role
    source_role = assignable_source_role || Role.where(builtin: 0).first || Role.first
    role_class = source_role ? source_role.class : Role
    role = role_class.new
    role.name = role_name
    role.position = (Role.where(builtin: 0).maximum(:position) || 0) + 1
  end
  assign_if_supported(role, :assignable, true)
  current_permissions = Array(role.permissions).map(&:to_sym)
  role.permissions = current_permissions | required_permissions
  role.save!

  # Em algumas revisões do OpenProject 17 o setter herdado pelo STI do papel
  # não marca a coluna como alterada. Persistir e conferir explicitamente evita
  # membros válidos que não aparecem em Assignee/Responsible.
  if role.has_attribute?(:assignable) && role[:assignable] != true
    role.update_column(:assignable, true)
  end
  role.reload
  if role.has_attribute?(:assignable) && role[:assignable] != true
    raise "O papel #{role.name} não pôde ser marcado como atribuível."
  end
  result[role_was_new ? :created : :reused] << "role:#{role.id}:#{role.name}"

  effective_role_permissions = Array(role.reload.permissions).map(&:to_sym)
  missing_role_permissions = required_permissions - effective_role_permissions
  if missing_role_permissions.any?
    raise "Permissões não persistidas no papel #{role.name}: #{missing_role_permissions.join(', ')}"
  end

  active_status = User.where(admin: true).where.not(status: nil).pick(:status)
  active_status ||= User.where.not(status: nil).pick(:status)
  users = catalog.fetch("test_users").map do |definition|
    user = User.find_by(login: definition.fetch("login"))
    was_new = user.nil?
    user ||= User.new(login: definition.fetch("login"))
    changed = false
    changed |= assign_if_supported(user, :firstname, definition.fetch("firstname"))
    changed |= assign_if_supported(user, :lastname, definition.fetch("lastname"))
    changed |= assign_if_supported(user, :mail, definition.fetch("mail"))
    changed |= assign_if_supported(user, :language, "pt-BR")
    changed |= assign_if_supported(user, :admin, false)
    changed |= assign_if_supported(user, :status, active_status) unless active_status.nil?
    user.password = test_user_password
    user.password_confirmation = test_user_password
    user.force_password_change = false if user.respond_to?(:force_password_change=)
    user.save! if was_new || changed || user.changed?
    result[was_new ? :created : (changed ? :updated : :reused)] << "user:#{user.id}:#{user.login}"
    user
  end

  # Espelha a regra informada para produção: todos os usuários de teste são
  # membros de todos os projetos ativos. Uma nova execução inclui projetos
  # criados posteriormente sem duplicar associações existentes.
  Project.where(active: true).find_each do |active_project|
    users.each do |user|
      membership = Member.find_by(project: active_project, principal: user)
      if membership
        unless membership.roles.exists?(role.id)
          membership.roles << role
          result[:updated] << "membership:#{membership.id}:#{active_project.identifier}:#{user.login}"
        else
          result[:reused] << "membership:#{membership.id}:#{active_project.identifier}:#{user.login}"
        end
      else
        membership = Member.create!(project: active_project, principal: user, roles: [role])
        result[:created] << "membership:#{membership.id}:#{active_project.identifier}:#{user.login}"
      end
    end
  end


  # Valida a regra usando a mesma relação de domínio consumida pelos campos de
  # Atribuído para/Encarregado. Se uma instalação exigir uma função atribuível
  # nativa além do papel customizado, ela é adicionada como compatibilidade.
  if project.respond_to?(:assignable_users)
    expected_user_ids = users.map(&:id)
    available_user_ids = Array(project.assignable_users).map(&:id) & expected_user_ids
    missing_user_ids = expected_user_ids - available_user_ids

    if missing_user_ids.any? && assignable_source_role && assignable_source_role.id != role.id
      users.select { |user| missing_user_ids.include?(user.id) }.each do |user|
        membership = Member.find_by!(project: project, principal: user)
        unless membership.roles.exists?(assignable_source_role.id)
          membership.roles << assignable_source_role
          result[:updated] << "assignable_role_fallback:#{membership.id}:#{assignable_source_role.id}:#{user.login}"
        end
      end
      project.reload
      available_user_ids = Array(project.assignable_users).map(&:id) & expected_user_ids
      missing_user_ids = expected_user_ids - available_user_ids
    end

    if missing_user_ids.any?
      missing_logins = users.select { |user| missing_user_ids.include?(user.id) }.map(&:login)
      raise "Usuários ainda não elegíveis para atribuição no projeto #{project.identifier}: #{missing_logins.join(', ')}"
    end
    result[:assignable_user_ids] = available_user_ids.sort
  else
    result[:warnings] << "A versão atual não expõe Project#assignable_users; validação de elegibilidade ignorada."
  end

  result[:project_id] = project.id
  result[:role_id] = role.id
  result[:role_assignable] = role.has_attribute?(:assignable) ? role[:assignable] : nil
  result[:user_ids] = users.to_h { |user| [user.login, user.id] }
  result[:custom_field_ids] = fields.to_h { |field| [field.name, field.id] }
  result[:priority_ids] = defined?(IssuePriority) ? IssuePriority.where(name: catalog.fetch("priorities").map { |item| item.fetch("name") }).to_h { |priority| [priority.name, priority.id] } : {}
  result[:forms] = types.to_h { |type| [type.name, fields.map(&:name)] }
end

puts "MVP_RESULT=#{JSON.generate(result)}"
