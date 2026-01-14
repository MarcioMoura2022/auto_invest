# 🚀 Auto-Invest - Sistema de Investimento Automatizado

Sistema profissional de investimento automatizado em criptomoedas com interface web moderna e funcionalidades avançadas de segurança.

## ✨ Funcionalidades Principais

- **Rebalanceamento Automático**: Distribuição automática de investimentos conforme alocação alvo
- **Interface Web Moderna**: Dashboard responsivo com gráficos e estatísticas em tempo real
- **Segurança Avançada**: CSRF protection, rate limiting, sessões seguras
- **API Binance Integrada**: Operações automáticas com rate limiting inteligente
- **Sistema de Logs**: Rastreamento completo de todas as operações
- **Backup e Recuperação**: Transações de banco com rollback automático
- **Monitoramento**: Verificação de status das ordens pendentes

## 🔒 Melhorias de Segurança Implementadas

- ✅ **Prepared Statements**: Proteção contra SQL Injection
- ✅ **CSRF Protection**: Tokens únicos para formulários
- ✅ **Rate Limiting**: Proteção contra ataques de força bruta
- ✅ **Sessões Seguras**: Cookies HttpOnly, Secure e SameSite
- ✅ **Validação de Entrada**: Filtros rigorosos para dados do usuário
- ✅ **Criptografia**: API keys criptografadas com AES-256
- ✅ **HTTPS Forçado**: Redirecionamento automático para conexão segura
- ✅ **Headers de Segurança**: XSS, Clickjacking e outras proteções

## 🛠️ Requisitos do Sistema

- **PHP**: 7.4 ou superior
- **PostgreSQL**: 10 ou superior
- **Extensões PHP**: PDO, OpenSSL, JSON
- **Servidor Web**: Apache/Nginx com mod_rewrite
- **SSL**: Certificado válido para HTTPS

## 📦 Instalação

### 1. Clone o repositório
```bash
git clone https://github.com/seu-usuario/auto-invest.git
cd auto-invest
```

### 2. Instale as dependências
```bash
composer install --no-dev --optimize-autoloader
```

### 3. Configure o VirtualHost
```apache
<VirtualHost *:80>
    ServerName auto-invest.local
    DocumentRoot /caminho/auto-invest/public
    
    <Directory /caminho/auto-invest/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    # Redirecionar para HTTPS
    RewriteEngine On
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
    ServerName auto-invest.local
    DocumentRoot /caminho/auto-invest/public
    
    SSLEngine on
    SSLCertificateFile /caminho/para/certificado.crt
    SSLCertificateKeyFile /caminho/para/chave.key
    
    <Directory /caminho/auto-invest/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 4. Execute o instalador
Acesse `https://seu-dominio/install/install.php` e siga o wizard de instalação.

### 5. Configure o CRON
```bash
# Rebalanceamento mensal (dia 1 às 00:00)
0 0 1 * * php /caminho/auto-invest/scripts/run_rebalance.php >> /var/log/auto-invest.log 2>&1

# Verificação diária de ordens pendentes (opcional)
0 */6 * * * php /caminho/auto-invest/scripts/run_rebalance.php --dry-run >> /var/log/auto-invest.log 2>&1
```

### 6. Remova a pasta de instalação
```bash
rm -rf install/
```

## 🔧 Configuração

### Variáveis de Ambiente
```bash
# Aporte padrão para rebalanceamento
export APORTE_USDT=1000

# Configurações de banco (se não usar config.php)
export DB_HOST=localhost
export DB_PORT=5432
export DB_NAME=auto_invest
export DB_USER=usuario
export DB_PASS=senha
```

### Configurações do Sistema
As configurações podem ser alteradas diretamente no banco na tabela `system_config`:

```sql
-- Exemplo de alteração de configuração
UPDATE system_config 
SET config_value = '10' 
WHERE config_key = 'max_retries';
```

## 📊 Uso

### Dashboard Web
- Acesse `https://seu-dominio/`
- Faça login com as credenciais criadas na instalação
- Visualize portfólio, ordens e estatísticas
- Execute rebalanceamentos manuais

### Script de Linha de Comando
```bash
# Rebalanceamento com aporte específico
php scripts/run_rebalance.php --aporte=500

# Modo simulação (sem executar ordens)
php scripts/run_rebalance.php --dry-run

# Modo verboso
php scripts/run_rebalance.php --verbose

# Ajuda
php scripts/run_rebalance.php --help
```

### API Binance
- Use API Key **SEM** permissão de saque
- Configure IPs permitidos na Binance
- Monitore logs para detectar problemas

## 🚨 Segurança

### Checklist de Produção
- [ ] HTTPS configurado e funcionando
- [ ] Pasta `/install` removida
- [ ] Permissões de arquivo configuradas corretamente
- [ ] Firewall configurado
- [ ] Backups automáticos configurados
- [ ] Monitoramento de logs ativo
- [ ] API Key da Binance sem permissão de saque

### Permissões de Arquivo
```bash
chmod 755 public/
chmod 644 public/*.php
chmod 600 config/config.php
chmod 755 scripts/
chmod 644 classes/*.php
```

## 📈 Monitoramento

### Logs do Sistema
- **Aplicação**: `/var/log/auto-invest.log`
- **Banco**: Tabela `logs_general`
- **Rebalanceamentos**: Tabela `rebalance_logs`
- **Tentativas de Login**: Tabela `login_attempts`

### Métricas Importantes
- Taxa de sucesso dos rebalanceamentos
- Tempo de execução das ordens
- Erros de API da Binance
- Tentativas de login maliciosas

## 🔄 Manutenção

### Backup do Banco
```bash
# Backup diário
pg_dump auto_invest > backup_$(date +%Y%m%d).sql

# Backup com compressão
pg_dump auto_invest | gzip > backup_$(date +%Y%m%d).sql.gz
```

### Atualizações
```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php scripts/run_rebalance.php --dry-run  # Testar antes
```

### Limpeza de Logs
```bash
# Limpar logs antigos (mais de 30 dias)
DELETE FROM logs_general WHERE created_at < NOW() - INTERVAL '30 days';
DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL '30 days';
```

## 🧪 Testes

### Executar Testes
```bash
# Instalar dependências de desenvolvimento
composer install

# Executar testes
composer test

# Análise estática
composer analyze

# Verificar padrões de código
composer cs
```

## 🆘 Suporte

### Problemas Comuns
1. **Erro de conexão com banco**: Verificar credenciais e firewall
2. **API Binance falhando**: Verificar rate limits e permissões
3. **Sessão expirando**: Verificar configuração de timeout
4. **Ordens não executando**: Verificar saldo USDT disponível

### Logs de Debug
```bash
# Ativar modo verboso
php scripts/run_rebalance.php --verbose

# Verificar logs do sistema
tail -f /var/log/auto-invest.log

# Verificar logs do banco
SELECT * FROM logs_general ORDER BY created_at DESC LIMIT 10;
```

## 📝 Changelog

### v2.0.0 (Atual)
- ✨ Interface web completamente redesenhada
- 🔒 Sistema de segurança robusto implementado
- 📊 Dashboard com estatísticas avançadas
- 🚀 Rate limiting e cache inteligente
- 🛡️ CSRF protection e validação rigorosa
- 📱 Design responsivo e moderno

### v1.0.0 (Anterior)
- 🔧 Funcionalidade básica de rebalanceamento
- 📊 Dashboard simples
- 🔐 Autenticação básica

## 📄 Licença

Este projeto está sob a licença MIT. Veja o arquivo `LICENSE` para detalhes.

## 🤝 Contribuição

1. Fork o projeto
2. Crie uma branch para sua feature (`git checkout -b feature/AmazingFeature`)
3. Commit suas mudanças (`git commit -m 'Add some AmazingFeature'`)
4. Push para a branch (`git push origin feature/AmazingFeature`)
5. Abra um Pull Request

## ⚠️ Disclaimer

**ATENÇÃO**: Este software é fornecido "como está" sem garantias. Investimentos em criptomoedas envolvem riscos significativos. Use por sua conta e risco.

- Teste extensivamente em ambiente de sandbox antes de usar com dinheiro real
- Monitore todas as operações regularmente
- Mantenha backups atualizados
- Use apenas API keys com permissões mínimas necessárias

---

**Desenvolvido com ❤️ para a comunidade de investidores em criptomoedas**
