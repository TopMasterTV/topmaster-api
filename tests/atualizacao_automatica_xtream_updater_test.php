<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/atualizacao_automatica_executor.php';

function xtreamOk(bool $condicao,string $mensagem):void {
    if(!$condicao) throw new RuntimeException('FALHA: '.$mensagem);
    fwrite(STDOUT,'OK: '.$mensagem.PHP_EOL);
}

$pdo=(new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$api=''; $timeout=0; $gravado=[];
$transporte=function(string $url,int $limite)use(&$api,&$timeout):array{
    $api=$url; $timeout=$limite;
    return ['ok'=>true,'corpo'=>json_encode(['user_info'=>['exp_date'=>'1798804800']])];
};
$persistencia=function(array $valores)use(&$gravado):void{$gravado=$valores;};
$updater=new AtualizacaoAutomaticaXtreamUpdater($pdo,10,$transporte,$persistencia);
$resultado=$updater->atualizar([
    'sistema_id'=>7,'url'=>'https://painel.exemplo.test:8443/caminho/player_api.php',
    'usuario'=>'usuario com espaco','senha'=>'senha&especial','vencimento_anterior'=>'2026-01-01'
]);
xtreamOk($api==='https://painel.exemplo.test:8443/player_api.php?username=usuario%20com%20espaco&password=senha%26especial','normaliza origem, remove caminho e codifica credenciais');
xtreamOk($timeout===10,'usa timeout equivalente ao fluxo manual');
xtreamOk(($resultado['status']??'')==='atualizado'&&($resultado['vencimento_novo']??'')==='2027-01-01','interpreta exp_date valido');
xtreamOk(($gravado['id']??0)===7&&($gravado['exp']??0)===1798804800&&($gravado['v']??'')==='2027-01-01','preserva persistencia de vencimento, exp_date e status');

$api='';
$updater->atualizar([
    'sistema_id'=>8,'url'=>'pendente','usuario'=>'','senha'=>'null',
    'm3u_url'=>'lista.exemplo.test/get.php?username=user%40teste&password=s%2Benha&type=m3u_plus',
    'vencimento_anterior'=>null
]);
xtreamOk($api==='http://lista.exemplo.test/player_api.php?username=user%40teste&password=s%2Benha','usa origem e credenciais da M3U como fallback e adiciona esquema');

$semResposta=new AtualizacaoAutomaticaXtreamUpdater($pdo,10,fn(string $url,int $limite):array=>['ok'=>false,'codigo'=>'TIMEOUT_PROVEDOR','detalhe'=>'Tempo limite ao consultar provedor'],$persistencia);
$falha=$semResposta->atualizar(['sistema_id'=>9,'url'=>'https://seguro.test','usuario'=>'u','senha'=>'s']);
xtreamOk(($falha['status']??'')==='falha'&&($falha['codigo_falha']??'')==='TIMEOUT_PROVEDOR','consulta sem resposta permanece falha');

$invalido=new AtualizacaoAutomaticaXtreamUpdater($pdo,10,fn(string $url,int $limite):array=>['ok'=>true,'corpo'=>'{"user_info":{}}'],$persistencia);
$falha=$invalido->atualizar(['sistema_id'=>10,'url'=>'https://seguro.test','usuario'=>'u','senha'=>'s']);
xtreamOk(($falha['codigo_falha']??'')==='RESPOSTA_SEM_VENCIMENTO','rejeita resposta sem exp_date valido');

$fonte=file_get_contents(dirname(__DIR__).'/atualizacao_automatica_executor.php');
xtreamOk(!str_contains($fonte,'TopMaster-Automacao/1.0'),'nao envia user-agent exclusivo da automacao');
