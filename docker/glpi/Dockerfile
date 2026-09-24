FROM glpi/glpi:11.0.9

USER root

# Ferramentas executadas apenas dentro da rede/local do GLPI para extrair
# conteúdo de anexos. Nenhum arquivo é enviado a serviço externo.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libreoffice-calc \
        libreoffice-writer \
        poppler-utils \
        tesseract-ocr \
        tesseract-ocr-por \
    && rm -rf /var/lib/apt/lists/*

USER www-data
