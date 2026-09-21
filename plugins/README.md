# Plugins locais do GLPI

Coloque cada plugin em uma subpasta própria deste diretório.

Exemplo:

```text
plugins/
└── demandas/
    ├── setup.php
    ├── hook.php
    └── src/
```

O volume já está montado em `/var/www/glpi/plugins` no contêiner do GLPI.
