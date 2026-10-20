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
3. Este dashboard foi projetado sem login para o cliente: qualquer pessoa que conheça ou descubra o endereço de um slug pode consultar as métricas daquele cliente, inclusive pela rota JSON da API. Não há uma lista de clientes no site, mas os slugs não são senhas. Prossiga apenas se esse modelo de acesso por link for adequado aos dados exibidos.
4. Crie `ads-dashboard-secrets.php` em `domains/publiai.com.br/`, fora de `public_html`, com o formato descrito em `krcon/api/README.md`.
5. Cadastre apenas os IDs e tokens de cada cliente na entrada correspondente a seu slug. Não envie o arquivo de segredos ao GitHub.
6. Abra `/` e `/politica-de-privacidade/` em uma janela sem login para confirmar que estão públicas. Teste cada painel e confirme que a resposta da API contém métricas, mas nunca tokens ou segredos.

O painel KRCON está pronto em `/krcon/`. Para acrescentar um cliente, copie essa pasta com o novo slug, edite `data-account-name`, `data-client-brand` e o logotipo em `index.html`, e adicione as credenciais desse cliente ao mapa privado do servidor. Não configure proteção por senha se o acesso por link for o modelo aprovado. A API usa o slug da pasta para selecionar somente a entrada correspondente; IDs de conta enviados pelo navegador são ignorados.

## OAuth / Google Cloud

Depois que as páginas estiverem publicadas e acessíveis por HTTPS, use na página de Branding:

- Página inicial: `https://ads.publiai.com.br/`
- Política de privacidade: `https://ads.publiai.com.br/politica-de-privacidade/`
- Domínio autorizado: `publiai.com.br` (validado no Google Search Console)

A página inicial identifica a plataforma e explica que o KRCON Dashboard Ads consulta relatórios do Google Ads. Como o aplicativo agora atende a painéis de vários clientes, alinhe o nome do aplicativo OAuth com a marca **Publiai Ads** no Google Auth Platform antes de solicitar verificação de marca. O nome do projeto Google Cloud pode continuar como está.

## Segurança

- Não coloque tokens, segredos OAuth, refresh tokens ou arquivos de credenciais no GitHub, mesmo que o repositório seja privado. O arquivo privado deve ficar fora de `public_html`.
- A API é somente leitura e mantém credenciais no servidor. O navegador recebe apenas os relatórios. Como o acesso do cliente não exige login, qualquer pessoa que obtenha a URL pode ver as métricas daquele slug.
- A API mantém configuração e limites por cliente, não aceita customer IDs pela URL e separa o cache por slug e IDs de conta configurados.
- A política de privacidade descreve o comportamento desta versão. Atualize-a se o painel passar a armazenar dados diferentes, adicionar login próprio ou mudar as integrações.
