<?php

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook de WhatsApp Cloud API (público: lo llama Meta, sin sesión ni CSRF).
// URL pública resultante: https://TU-DOMINIO/api/webhooks/whatsapp
Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle']);
