# API de relatórios por cliente

Esta API é o limite do servidor para consultas de Meta Ads e Google Ads. O navegador chama `GET /krcon/api/v1/report.php?period=30d`; tokens e IDs de conta nunca são enviados ao navegador.

## Isolamento por cliente

- O cliente é resolvido pelo segmento de URL imediatamente anterior a `/api/`. Exemplos: `/krcon/api/v1/report.php` e `/cliente-xpto/api/v1/report.php`.
- O servidor procura as credenciais somente na entrada correspondente do mapa privado `tenants[slug]`.
- Nenhum ID de conta vindo da URL, formulário ou navegador é aceito.
- Cache e limite de requisições são separados por cliente.
- O relatório é somente leitura. Google Ads usa `search`; Meta Ads usa Insights.
- Os relatórios ficam disponíveis a quem consegue acessar o endereço e a API. Ative proteção por senha no diretório de cada cliente na Hostinger antes de adicionar credenciais reais.

## Arquivo privado de configuração

Crie `ads-dashboard-secrets.php` fora de `public_html`. Na Hostinger, com o document root do subdomínio em `domains/publiai.com.br/public_html/ads`, o local recomendado é `domains/publiai.com.br/ads-dashboard-secrets.php`. Não salve esse arquivo no repositório nem em qualquer pasta pública.

O arquivo deve retornar um mapa por cliente. Exemplo com valores fictícios:

```php
<?php
return [
    'tenants' => [
        'krcon' => [
            'GOOGLE_CLIENT_ID' => 'CLIENT_ID_AQUI',
            'GOOGLE_CLIENT_SECRET' => 'CLIENT_SECRET_AQUI',
            'GOOGLE_REFRESH_TOKEN' => 'REFRESH_TOKEN_AQUI',
            'GOOGLE_CUSTOMER_ID' => '1234567890',
            'GOOGLE_ADS_API_VERSION' => 'v25',
            // 'GOOGLE_LOGIN_CUSTOMER_ID' => '0987654321',
            // 'META_ACCESS_TOKEN' => 'META_TOKEN_AQUI',
            // 'META_AD_ACCOUNT_ID' => 'act_123456789012345',
            // 'META_API_VERSION' => 'vXX.0',
            'DASHBOARD_TIMEZONE' => 'America/Sao_Paulo',
        ],
        'cliente-xpto' => [
            // Credenciais e IDs próprios deste cliente.
        ],
    ],
];
```

O slug `krcon` é a pasta `/krcon/`. Para cadastrar outro cliente, crie a pasta com o slug correspondente e uma entrada independente no mapa. Não copie as credenciais de um cliente para outro. Variáveis de ambiente também podem ser específicas por cliente, no formato `ADS_KRCON_GOOGLE_CLIENT_ID`, `ADS_CLIENTE_XPTO_GOOGLE_CLIENT_ID` etc. Para manter compatibilidade com a primeira instalação, variáveis antigas sem prefixo são lidas apenas para `krcon`.

O caminho do arquivo pode ser definido pela variável `ADS_PRIVATE_CONFIG_FILE`, desde que esteja fora de `public_html`. Sem ela, a API procura o arquivo no diretório acima de `public_html` na Hostinger ou acima do `DOCUMENT_ROOT` em outros ambientes.

## Operação

- Métodos aceitos: somente `GET`.
- Períodos aceitos: `7d`, `30d`, `90d`, `this_month`, `last_month`.
- Limite: 30 solicitações por minuto por endereço cliente e por slug.
- Cache: cinco minutos por cliente e período.
- Respostas não incluem credenciais nem detalhes de erro upstream.
- O OAuth refresh token deve ser removido do arquivo privado quando a integração for revogada.

Consulte o `README.md` na raiz do repositório para o procedimento completo de publicação e proteção na Hostinger.
