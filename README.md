# Publiai Ads para Hostinger

Este repositório está organizado para ser publicado na raiz do subdomínio `ads.publiai.com.br`. A raiz contém a apresentação pública da plataforma e a política de privacidade; cada painel de cliente fica em sua própria pasta.

## Estrutura publicada

```text
/
├── index.html                         # Página geral Publiai Ads
├── politica-de-privacidade/index.html # Política compartilhada
└── krcon/                              # Painel KRCON
    ├── index.html
    ├── assets/
    └── api/
```

URLs planejadas:

- Página pública e OAuth: `https://ads.publiai.com.br/`
- Política de privacidade: `https://ads.publiai.com.br/politica-de-privacidade/`
- Painel KRCON: `https://ads.publiai.com.br/krcon/`
- Próximos clientes: `https://ads.publiai.com.br/cliente-xpto/`, `https://ads.publiai.com.br/cliente-c/` etc.

## Publicar pela Hostinger

1. Configure a implantação GitHub da Hostinger para publicar o repositório em `domains/publiai.com.br/public_html/ads`.
2. Ative HTTPS/SSL para `ads.publiai.com.br`.
3. Antes de inserir credenciais de anúncios, proteja a pasta `krcon` com **Directory Privacy/Proteção de diretório** no hPanel da Hostinger. Faça o mesmo para cada pasta de cliente. Sem esse controle, qualquer pessoa que alcançar o endereço consegue consultar os relatórios pela API.
4. Crie `ads-dashboard-secrets.php` em `domains/publiai.com.br/`, fora de `public_html`, com o formato descrito em `krcon/api/README.md`.
5. Cadastre apenas os IDs e tokens de cada cliente na entrada correspondente a seu slug. Não envie o arquivo de segredos ao GitHub.
6. Abra `/` e `/politica-de-privacidade/` em uma janela sem login para confirmar que estão públicas. Teste os painéis com as credenciais configuradas.

O painel KRCON está pronto em `/krcon/`. Para acrescentar um cliente, copie essa pasta com o novo slug, edite `data-account-name`, `data-client-brand` e o logotipo em `index.html`, proteja a nova pasta na Hostinger e adicione as credenciais do cliente ao mapa privado do servidor. A API usa o slug da pasta para selecionar somente a entrada correspondente; IDs de conta enviados pelo navegador são ignorados.

## OAuth / Google Cloud

Depois que as páginas estiverem publicadas e acessíveis por HTTPS, use na página de Branding:

- Página inicial: `https://ads.publiai.com.br/`
- Política de privacidade: `https://ads.publiai.com.br/politica-de-privacidade/`
- Domínio autorizado: `publiai.com.br` (validado no Google Search Console)

A página inicial identifica a plataforma e explica que o KRCON Dashboard Ads consulta relatórios do Google Ads. Como o aplicativo agora atende a painéis de vários clientes, alinhe o nome do aplicativo OAuth com a marca **Publiai Ads** no Google Auth Platform antes de solicitar verificação de marca. O nome do projeto Google Cloud pode continuar como está.

## Segurança

- O repositório é público. Não coloque tokens, segredos OAuth, refresh tokens, IDs de conta ou arquivos de credenciais neste repositório.
- A API é somente leitura, mas os relatórios são informações de negócio. A proteção de diretório da Hostinger é necessária antes de configurar dados reais.
- A API mantém credenciais fora da pasta pública, separa configuração, cache e limite de requisições por cliente e não aceita customer IDs pela URL.
- A política de privacidade descreve o comportamento desta versão. Atualize-a se o painel passar a armazenar dados diferentes, adicionar login próprio ou mudar as integrações.
