# frozen_string_literal: true

require "json"

project_identifier = "homologacao-gestao-demandas"
integration_login = "integracao.demandas"
integration_password = ENV.fetch("MVP_OPENPROJECT_INTEGRATION_PASSWORD")

result = { application: "OpenProject", created: [], reused: [], warnings: [] }

# Os identificadores internos dos formatos não são os mesmos rótulos exibidos
# pela interface. No OpenProject 17, por exemplo, número inteiro é `int` e uma
# URL é armazenada por um campo `string`. Validar contra o registro real evita
# criar um CustomField com formato inexistente e falhar apenas no callback de
# validação do valor padrão.
def resolve_custom_field_format(semantic_format)
  aliases = {
    "integer" => %w[int integer],
    "link" => %w[link string],
    "string" => %w[string]
  }
  candidates = aliases.fetch(semantic_format.to_s, [semantic_format.to_s])
  resolved = candidates.find do |candidate|
    OpenProject::CustomFieldFormat.find_by(name: candidate)
  end
  return resolved if resolved

  raise "Formato de campo customizado não suportado: #{semantic_format} (tentativas: #{candidates.join(', ')})"
end

ActiveRecord::Base.transaction do
  project = Project.find_or_initialize_by(identifier: project_identifier)
  if project.new_record?
    project.name = "Homologação — Gestão de Demandas"
    project.public = false
    project.active = true

    # OpenProject 17.7 tornou workspace_type obrigatório. Reutilizar um valor
    # já aceito pela própria instalação evita acoplamento a enums internos que
    # podem variar entre edições e versões.
    if project.respond_to?(:workspace_type=)
      workspace_type = Project.where.not(workspace_type: [nil, ""])
                              .distinct
                              .pick(:workspace_type)
      workspace_type ||= "project"
      project.workspace_type = workspace_type
    end

    project.save!
    result[:created] << "project:#{project.id}"
  else
    result[:reused] << "project:#{project.id}"
  end

  # O módulo interno `costs` corresponde a "Tempo e custos". As permissões
  # do papel, isoladamente, não tornam o formulário de apontamento disponível.
  required_project_modules = ["work_package_tracking", "costs"]
  current_project_modules = Array(project.enabled_module_names).map(&:to_s)
  missing_project_modules = required_project_modules - current_project_modules
  if missing_project_modules.any?
    project.enabled_module_names = current_project_modules | required_project_modules
    project.save!
    result[:created] << "project_modules:#{missing_project_modules.join(',')}"
  else
    result[:reused] << "project_modules:ok"
  end

  source_type = Type.where(is_standard: false).first || Type.first
  types = ["User Story", "Bug"].map do |name|
    type = Type.where("LOWER(name) = ?", name.downcase).first
    unless type
      type = Type.create!(
        name: name,
        is_in_roadmap: true,
        is_milestone: false,
        is_default: false,
        position: (Type.maximum(:position) || 0) + 1
      )
      type.workflows.copy_from_type(source_type) if source_type && source_type != type
      result[:created] << "type:#{type.id}:#{name}"
    else
      result[:reused] << "type:#{type.id}:#{name}"
    end
    project.types << type unless project.types.exists?(type.id)
    type
  end

  fields = [
    ["ServiceDesk", "integer"],
    ["Link ServiceDesk", "link"]
  ].map do |name, format|
    field = WorkPackageCustomField.where("LOWER(name) = ?", name.downcase).first
    unless field
      field = WorkPackageCustomField.new
      {
        name: name,
        field_format: resolve_custom_field_format(format),
        is_required: false,
        is_for_all: false,
        visible: true,
        searchable: true
      }.each do |attribute, value|
        writer = "#{attribute}="
        field.public_send(writer, value) if field.respond_to?(writer)
      end
      field.save!
      result[:created] << "custom_field:#{field.id}:#{name}"
    else
      result[:reused] << "custom_field:#{field.id}:#{name}"
    end
    field.projects << project unless field.projects.exists?(project.id)
    types.each { |type| field.types << type unless field.types.exists?(type.id) }
    field
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

  role = Role.where("LOWER(name) = ?", "Integração GLPI".downcase).first
  role_was_new = role.nil?
  unless role
    # Role passou a utilizar subclasses/tipos explícitos no OpenProject 17.7.
    # Criar pela mesma classe de um papel de projeto existente mantém o novo
    # registro dentro dos tipos aceitos pela instalação.
    source_role = Role.where(builtin: 0).first || Role.first
    role_class = source_role ? source_role.class : Role
    role = role_class.new
    role.name = "Integração GLPI"
    role.position = (Role.where(builtin: 0).maximum(:position) || 0) + 1
  else
    result[:reused] << "role:#{role.id}"
  end

  current_permissions = Array(role.permissions).map(&:to_sym)
  missing_permissions = required_permissions - current_permissions
  if missing_permissions.any?
    role.permissions = current_permissions | required_permissions
    role.save!
    result[:created] << "role_permissions:#{missing_permissions.join(',')}"
  else
    # Garante que um papel recém-criado também seja persistido quando todas as
    # permissões já tiverem sido atribuídas pelo modelo da instalação.
    role.save! if role.new_record?
    result[:reused] << "role_permissions:ok"
  end
  result[:created] << "role:#{role.id}" if role_was_new

  user = User.find_by(login: integration_login)
  unless user
    active_status = User.where(admin: true).where.not(status: nil).pick(:status)
    active_status ||= User.where.not(status: nil).pick(:status)

    user = User.new(
      login: integration_login,
      firstname: "Integração",
      lastname: "Demandas",
      mail: "integracao.demandas@mvp.local",
      language: "pt-BR",
      admin: false,
      status: active_status
    )
    user.password = integration_password
    user.password_confirmation = integration_password
    user.force_password_change = false
    user.save!
    result[:created] << "user:#{user.id}"
  else
    # Mantém a senha local e o usuário técnico sincronizados quando uma
    # execução anterior terminou antes de gravar o integration.env.
    user.password = integration_password
    user.password_confirmation = integration_password
    user.force_password_change = false if user.respond_to?(:force_password_change=)
    user.save!
    result[:reused] << "user:#{user.id}"
  end

  membership = Member.find_by(project: project, principal: user)
  if membership
    membership.roles << role unless membership.roles.exists?(role.id)
    result[:reused] << "membership:#{membership.id}"
  else
    membership = Member.create!(project: project, principal: user, roles: [role])
    result[:created] << "membership:#{membership.id}"
  end

  result[:effective_permissions] = {
    view_work_packages: user.allowed_in_project?(:view_work_packages, project),
    add_work_packages: user.allowed_in_project?(:add_work_packages, project),
    edit_work_packages: user.allowed_in_project?(:edit_work_packages, project),
    view_time_entries: user.allowed_in_project?(:view_time_entries, project),
    log_own_time: user.allowed_in_project?(:log_own_time, project)
  }
  unless result[:effective_permissions].values.all?
    result[:warnings] << "As permissões efetivas do usuário técnico ainda não foram integralmente aplicadas."
  end

  Setting.api_tokens_enabled = "1" unless Setting.api_tokens_enabled?
  rotate_api_token = ENV["MVP_OPENPROJECT_ROTATE_API_TOKEN"] == "1"
  api_token = user.api_tokens.first
  if api_token && rotate_api_token
    user.api_tokens.destroy_all
    api_token = Token::API.create!(user: user)
    result[:created] << "api_token:rotated"
    result[:api_token] = api_token.plain_value
  elsif api_token
    result[:reused] << "api_token:existing"
    result[:warnings] << "O token de API já existia e não pode ser revelado novamente. Gere outro pela interface se o integration.env não o possuir."
  else
    api_token = Token::API.create!(user: user)
    result[:created] << "api_token:new"
    result[:api_token] = api_token.plain_value
  end

  result[:project_id] = project.id
  result[:project_identifier] = project.identifier
  result[:integration_user_id] = user.id
  result[:integration_login] = user.login
  result[:role_id] = role.id
  result[:type_ids] = types.to_h { |type| [type.name, type.id] }
  result[:custom_field_ids] = fields.to_h { |field| [field.name, field.id] }
end

puts "MVP_RESULT=#{JSON.generate(result)}"
