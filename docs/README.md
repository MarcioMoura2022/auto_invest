# 📸 Visualização das Telas do Sistema

Este diretório contém a documentação visual das telas do sistema Auto Invest.

## 📄 Arquivos Disponíveis

- **telas-sistema.html** - Visualização completa de todas as telas principais do sistema

## 🖥️ Como Visualizar

### Opção 1: Abrir diretamente no navegador
```bash
# No Windows
start docs/telas-sistema.html

# No Linux/Mac
xdg-open docs/telas-sistema.html
# ou
open docs/telas-sistema.html
```

### Opção 2: Servir via servidor web local
```bash
# Com PHP
php -S localhost:8000
# Acesse: http://localhost:8000/docs/telas-sistema.html

# Com Python
python -m http.server 8000
# Acesse: http://localhost:8000/docs/telas-sistema.html
```

### Opção 3: Imprimir para PDF
1. Abra o arquivo `telas-sistema.html` no navegador
2. Pressione `Ctrl+P` (ou `Cmd+P` no Mac)
3. Salve como PDF

## 📋 Telas Incluídas

1. **Tela de Login**
   - Interface de autenticação moderna
   - Design com gradiente roxo/azul
   - Validação de formulário
   - Proteção CSRF

2. **Dashboard Principal**
   - Cards de estatísticas
   - Gráfico de composição da carteira
   - Tabela de ativos
   - Histórico de rebalanceamentos
   - Últimas ordens executadas
   - Sidebar de navegação

3. **Modal de Rebalanceamento**
   - Formulário para rebalanceamento manual
   - Validação de aporte
   - Confirmação de ação

4. **Tela de Instalação**
   - Wizard de configuração inicial
   - Configuração de banco de dados
   - Criação de usuário admin
   - Configuração da API Binance
   - Configuração de criptografia

## 🎨 Características Visuais

- **Design Moderno**: Interface limpa e profissional
- **Responsivo**: Adaptável a diferentes tamanhos de tela
- **Cores**: Gradiente roxo/azul (#667eea → #764ba2)
- **Ícones**: Bootstrap Icons para melhor UX
- **Componentes**: Cards, modais, tabelas e gráficos

## 📝 Notas

- Estas são **representações visuais** das telas
- Os dados exibidos são **exemplos** para demonstração
- As funcionalidades reais podem variar ligeiramente
- Para ver as telas reais, instale e acesse o sistema

## 🔄 Atualizações

Este arquivo será atualizado sempre que houver mudanças significativas na interface do sistema.

---

**Última atualização**: Dezembro 2024
