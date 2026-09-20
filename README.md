# sendflit/sendflit — PHP

Transactional email for applications and AI agents. Zero dependencies (cURL + JSON), PHP 7.4+.

## Install

```bash
composer require sendflit/sendflit
```

## Usage

```php
require 'vendor/autoload.php';

$sf = new SendFlit('re_...');

$res = $sf->send([
    'from'    => 'you@yourdomain.com',
    'to'      => 'user@example.com',
    'subject' => 'Confirm your email',
    'html'    => "<p>Click <a href='...'>here</a>.</p>",
]);
echo $res['id'], $res['status'];
```

### Attachments / batch / webhook

```php
$sf->send([
    'from' => 'billing@yourdomain.com', 'to' => 'u@example.com', 'subject' => 'Receipt',
    'attachments' => [['filename' => 'receipt.pdf', 'content' => $pdfBytes]],
]);

$sf->batch([ ['from' => 'you@yourdomain.com', 'to' => 'a@example.com', 'subject' => 'Hi'] ]);

$ok = SendFlit::verifyWebhookSignature($secret, $rawBody, $_SERVER['HTTP_X_SENDFLIT_SIGNATURE'] ?? '');
```

Retries 429/5xx with backoff; errors throw `SendFlitException` with `->getCode()`/`->body`.

MIT © SendFlit
