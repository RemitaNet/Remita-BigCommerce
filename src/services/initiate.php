<?php
// public/initiate.php

require_once __DIR__ . '/PaymentController.php';

$controller = new PaymentController();
$controller->handleInitiatePayment();