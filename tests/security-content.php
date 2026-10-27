<?php
declare(strict_types=1);
if (getenv('GLPI_DB_HOST') !== 'demandas-test-021-db') throw new RuntimeException('Somente banco descartável.');
require '/var/www/glpi/vendor/autoload.php';
(new Glpi\Kernel\Kernel('production', false))->boot();
use GlpiPlugin\Demandas\{CredentialStore as Secrets, Config as DC, IntegrationSecurity as Net, AttachmentSecurity as Files, WebhookHandler};
global $DB;
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
function rejects(callable $call): bool { try { $call(); return false; } catch (DomainException) { return true; } }
check(Secrets::encrypted(), 'migration marker present');
$config = DC::all();
$raw = Config::getConfigurationValues(DC::CONTEXT);
foreach (Secrets::KEYS as $key) if (($config[$key] ?? '') !== '') check($raw[$key] !== $config[$key], 'stored credential encrypted: ' . $key);
$tokens = [];
foreach ($DB->request(['FROM'=>'glpi_plugin_demandas_user_tokens']) as $row) $tokens[$row['id']] = Secrets::decode($row['openproject_api_token']);
$before = json_encode(iterator_to_array($DB->request(['FROM' => 'glpi_plugin_demandas_user_tokens', 'ORDER' => 'id'])));
Secrets::migrate(); check($before === json_encode(iterator_to_array($DB->request(['FROM' => 'glpi_plugin_demandas_user_tokens', 'ORDER' => 'id']))), 'repeated migration does not reencrypt tokens');

// Rotate using a private copy of the key and a rolled-back DB transaction.
// The container's real key file and credentials are never replaced.
$directory = sys_get_temp_dir() . '/demandas-key-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
copy(GLPI_CONFIG_DIR . '/glpicrypt.key', $directory . '/glpicrypt.key');
$DB->beginTransaction();
try {
    $key = new GLPIKey($directory);
    check($key->generate(true), 'native GLPI key rotation includes plugin fields and configurations');
    foreach ($DB->request(['FROM'=>'glpi_plugin_demandas_user_tokens']) as $row) check($key->decrypt($row['openproject_api_token']) === $tokens[$row['id']], 'rotated personal token retains value');
    $rotated = Config::getConfigurationValues(DC::CONTEXT);
    foreach (Secrets::KEYS as $name) check($key->decrypt($rotated[$name] ?? '') === ($config[$name] ?? ''), 'rotated config retains value: ' . $name);
} finally { $DB->rollBack(); unlink($directory . '/glpicrypt.key'); rmdir($directory); }
check(DC::all() === $config, 'rotation test preserves original configuration');
check(Secrets::decode(Secrets::encode('synthetic-roundtrip')) === 'synthetic-roundtrip', 'authenticated encryption roundtrip');
$DB->beginTransaction();
try {
    foreach ($tokens as $id => $token) $DB->update('glpi_plugin_demandas_user_tokens',['openproject_api_token'=>$token],['id'=>$id]);
    foreach (Secrets::KEYS as $name) $DB->update('glpi_configs',['value'=>$config[$name]??''],['context'=>DC::CONTEXT,'name'=>$name]);
    $DB->delete('glpi_configs',['context'=>DC::CONTEXT,'name'=>'credentials_encrypted_0340']);
    $DB->insert('glpi_plugin_demandas_user_tokens',['users_id'=>999999996,'openproject_api_token'=>str_repeat('X',65000)]);
    $rawBefore=Config::getConfigurationValues(DC::CONTEXT);
    try { Secrets::migrate(); throw new LogicException('Expected migration failure'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(),'excede'), 'oversized legacy credential interrupts migration safely'); }
    check(Config::getConfigurationValues(DC::CONTEXT)===$rawBefore && !Secrets::encrypted(), 'failed migration rolls back all config encryption and marker');
    foreach($DB->request(['FROM'=>'glpi_plugin_demandas_user_tokens']) as $row) if(isset($tokens[$row['id']])) check($row['openproject_api_token']===$tokens[$row['id']], 'failed migration preserves legacy personal token');
} finally { $DB->rollBack(); Secrets::register(); }


foreach (['file:///etc/passwd','https://user:pass@example.test/api','//example.test','https://example.test/#secret',"https://example.test/\n",'https://example.test/?token=x'] as $url) check(rejects(fn() => Net::baseUrl($url)), 'invalid base URL rejected');
check(Net::baseUrl('http://openproject/api/v3/') === 'http://openproject/api/v3', 'internal Docker URL preserved');
foreach (['//evil.test/a','https://evil.test/','../users','%2e%2e/users','%252e%252e/users','users\\..\\admin'] as $uri) check(rejects(fn() => Net::resource($uri)), 'unsafe API path rejected');
check(Net::resource('work_packages?filters=%5B%5D') === 'work_packages?filters=%5B%5D', 'encoded query preserved');
$cause = new GuzzleHttp\Exception\RequestException('sensitive-token and client content', new GuzzleHttp\Psr7\Request('POST','https://example.test'), new GuzzleHttp\Psr7\Response(422,[], 'private response'));
$safe = Net::failure($cause);
check(!str_contains($safe->getMessage(), 'sensitive') && !str_contains($safe->getMessage(), 'private') && $safe->getPrevious() === $cause, 'safe message preserves internal status classification');

$path = tempnam(sys_get_temp_dir(), 'demandas-content-');
try {
    file_put_contents($path, '<?php echo "synthetic";');
    check(rejects(fn() => Files::inspect($path,'png')), 'PHP renamed PNG rejected');
    check(rejects(fn() => Files::inspect($path,'pdf')), 'PHP renamed PDF rejected');
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9WQAAAAASUVORK5CYII='));
    check(Files::inspect($path,'png')['mime'] === 'image/png', 'actual image MIME accepted');
    file_put_contents($path, '%PDF-1.4 synthetic QA');
    check(Files::inspect($path,'pdf')['mime'] === 'application/pdf', 'PDF signature accepted');
    check(rejects(fn() => Files::upload(['tmp_name'=>$path,'error'=>0,'name'=>'test.pdf'],['pdf'])), 'local paths are not accepted as HTTP uploads');
    unlink($path);
    $zip = new ZipArchive(); $zip->open($path,ZipArchive::CREATE); $zip->addFromString('bomb',str_repeat('A',2*1024*1024)); $zip->close();
    check(rejects(fn() => Files::inspect($path,'zip')), 'high expansion archive rejected');
    unlink($path);
    $zip = new ZipArchive(); $zip->open($path,ZipArchive::CREATE); $zip->addFromString('word/document.xml','<document>QA</document>'); $zip->close();
    check(Files::inspect($path,'docx')['size'] > 0, 'Office container inspected without extracting');
    check(rejects(fn() => Files::inspect($path,'xlsx')), 'wrong Office container rejected');
} finally { if (is_file($path)) unlink($path); }
$body = json_encode(['action'=>'ignored']);
foreach (['sha1','sha256'] as $algorithm) {
    $signature = $algorithm . '=' . hash_hmac($algorithm,$body,DC::webhookSecret());
    check(!(new WebhookHandler())->handle($body,$signature)['processed'], 'signed webhook compatibility: ' . $algorithm);
}
$service = new GlpiPlugin\Demandas\WorkPackageAnalysisService();
$run = new ReflectionMethod($service, 'runTool');
check(trim((string)$run->invoke($service, ['timeout','35s',PHP_BINARY,'-r','echo "QA extraction";'])) === 'QA extraction', 'bounded processor returns normal output');
check($run->invoke($service, ['timeout','35s',PHP_BINARY,'-r','echo str_repeat("A",2097152);']) === null, 'processor rejects excessive output');
check($run->invoke($service, ['timeout','35s',PHP_BINARY,'-r','fwrite(STDERR,str_repeat("A",2097152));']) === null, 'stderr drained without deadlock and bounded');
$extract = new ReflectionMethod($service, 'extractAttachment');
$pdfPath = sys_get_temp_dir().'/demandas-qa-'.bin2hex(random_bytes(8)).'.pdf';
$pdf = new GLPIPDF(['orientation'=>'P','font_size'=>10],1,'QA');
$pdf->writeHTML('<p>Teste seguro de extracao PDF</p>');
$pdf->Output($pdfPath,'F');
try { check(str_contains((string)$extract->invoke($service,['path'=>$pdfPath,'size'=>filesize($pdfPath),'name'=>'qa.pdf']),'Teste seguro'), 'real PDF extracted under resource limits'); }
finally { unlink($pdfPath); }
$odtPath = sys_get_temp_dir().'/demandas-qa-'.bin2hex(random_bytes(8)).'.odt';
$zip=new ZipArchive();$zip->open($odtPath,ZipArchive::CREATE);
$zip->addFromString('mimetype','application/vnd.oasis.opendocument.text');
$zip->addFromString('META-INF/manifest.xml','<?xml version="1.0"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"><manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/></manifest:manifest>');
$zip->addFromString('content.xml','<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.2"><office:body><office:text><text:p>Teste seguro de extracao Office</text:p></office:text></office:body></office:document-content>');$zip->close();
try { check(str_contains((string)$extract->invoke($service,['path'=>$odtPath,'size'=>filesize($odtPath),'name'=>'qa.odt']),'Teste seguro'), 'real Office document extracted under resource limits'); }
finally { unlink($odtPath); }
check(!str_contains(Net::message(new RuntimeException('SQL synthetic-sensitive-data')), 'synthetic-sensitive-data'), 'unexpected SQL diagnostics hidden');
$DB->beginTransaction();
try {
    Config::setConfigurationValues(DC::CONTEXT,['classification_source_json'=>'{"mode":"ticket_field","field":"type"}','classification_rules_json'=>'[]']);
    $ticket=new Ticket();$ticket->fields=['id'=>1,'type'=>'<img src=x onerror=alert(1)>'];
    try { GlpiPlugin\Demandas\ClassificationPolicy::assertTypeAllowed($ticket,['name'=>'Other']); }
    catch(RuntimeException $e) { check(str_contains(Net::html($e),'&lt;img') && !str_contains(Net::html($e),'<img'), 'dynamic validation messages escaped for native raw HTML flashes'); }
} finally { $DB->rollBack(); }
echo "OK security content\n";

