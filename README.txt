# Auto-Invest (PHP + PostgreSQL + Binance API)

## Instalação rápida
1) `composer install`
2) Configure seu VirtualHost para apontar para `public/`.
3) Acesse `/install/install.php` no navegador e complete o wizard.
4) Remova a pasta `install/` após instalar.
5) Configure o CRON para `scripts/run_rebalance.php`.

### CRON (mensal, dia 1 às 00:00)
```
0 0 1 * * php /caminho/auto_invest/scripts/run_rebalance.php >> /var/log/auto_invest.log 2>&1
```

## Segurança
- Use API Key sem permissão de saque.
- Use HTTPS.
- Guarde a ENCRYPTION_KEY com segurança.
