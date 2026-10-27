<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') throw new RuntimeException('Somente banco descartável.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production',false))->boot();
use GlpiPlugin\Demandas\{CredentialStore,OpenProjectClient,Config as DC};
global $DB;
$DB->beginTransaction();
try {
    CredentialStore::saveConfig(['openproject_internal_url'=>'http://127.0.0.1:8399/api/v3','openproject_automation_api_token'=>'synthetic-security']);
    foreach (['redirect','large','invalid'] as $mode) {
        file_put_contents('/tmp/demandas-security-mode',$mode);
        try { OpenProjectClient::forAutomation()->getProjects(); throw new LogicException('Expected rejection: '.$mode); }
        catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(),'Referência:') || $e->getPrevious() === null) throw $e;
            echo "PASS: safe rejection of $mode response with internal cause preserved\n";
        }
    }
    if (is_file('/tmp/demandas-security-redirect-hit')) throw new RuntimeException('Redirect followed');
    file_put_contents('/tmp/demandas-security-mode','ok');
    if (OpenProjectClient::forAutomation()->getProjects() !== []) throw new RuntimeException('Normal response failed');
    echo "PASS: redirects never followed; valid response accepted\n";
} finally { $DB->rollBack(); }

$client = new GuzzleHttp\Client(['base_uri'=>'http://127.0.0.1:80','http_errors'=>false]);
$path = '/plugins/demandas/webhook.php';
$response = $client->post($path,['body'=>str_repeat('x',2*1024*1024+1)]);
if ($response->getStatusCode()!==413) throw new RuntimeException('Oversized webhook not rejected');
$body=str_repeat('[',80).'0'.str_repeat(']',80);
$response=$client->post($path,['body'=>$body,'headers'=>['X-Op-Signature'=>'sha256='.hash_hmac('sha256',$body,DC::webhookSecret())]]);
if ($response->getStatusCode()!==400) throw new RuntimeException('Deep webhook not rejected');
echo "PASS: HTTP webhook size and JSON nesting limits\n";
