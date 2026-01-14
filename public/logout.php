<?php
require_once __DIR__ . '/../classes/auth.php';

// Fazer logout seguro
Auth::logout();

// Redirecionar para login
header('Location: login.php');
exit;
