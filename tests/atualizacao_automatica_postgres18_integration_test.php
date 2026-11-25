<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/atualizacao_automatica_config_repository.php';
require_once dirname(__DIR__) . '/atualizacao_automatica_executor.php';

function pg18Ok(bool $condicao, string $mensagem): void {
    if (!$condicao) throw new RuntimeException('FALHA: ' . $mensagem);
    fwrite(STDOUT, 'OK: ' . $mensagem . PHP_EOL);
}

$url = getenv('DATABASE_URL');
if (!is_string($url) || $url === '') throw new RuntimeException('DATABASE_URL isolada obrigatoria');
$partes = parse_url($url);
$host = $partes['host'] ?? '';
$banco = ltrim((string)($partes['path'] ?? ''), '/');
if (!in_array($host, ['127.0.0.1', 'localhost', 'postgres'], true)
    || $banco !== 'topmaster_auto_update_test') {
    throw new RuntimeException('Teste recusou banco que nao e local e isolado');
}
$porta = (int)($partes['port'] ?? 5432);
$usuario = (string)($partes['user'] ?? '');
$senha = (string)($partes['pass'] ?? '');
$dsn = "pgsql:host={$host};port={$porta};dbname={$banco}";
$opcoesPdo = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$pdo = new PDO($dsn, $usuario, $senha, $opcoesPdo);
$pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
$pdo->exec(<<<'SQL'
CREATE TABLE admins (id SERIAL PRIMARY KEY);
CREATE TABLE clientes (id SERIAL PRIMARY KEY, nome VARCHAR(200) NOT NULL, marcador VARCHAR(40));
CREATE TABLE modelos_sistemas (id SERIAL PRIMARY KEY, nome VARCHAR(150), url_padrao TEXT);
CREATE TABLE sistemas (
 id SERIAL PRIMARY KEY, cliente_id INTEGER NOT NULL REFERENCES clientes(id),
 modelo_id INTEGER REFERENCES modelos_sistemas(id), usuario TEXT, senha TEXT,
 m3u_url TEXT, status TEXT, exp_date BIGINT, vencimento DATE, marcador VARCHAR(40)
);
INSERT INTO admins(id) VALUES (1);
INSERT INTO modelos_sistemas(id,nome,url_padrao) VALUES (1,'MODELO TESTE','https://invalid.test');
SQL);
foreach (['012_criar_provedor_blocos.sql','013_criar_atualizacao_automatica_config.sql','015_criar_atualizacao_automatica_execucoes.sql'] as $migration) {
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/' . $migration);
    if (!is_string($sql)) throw new RuntimeException('Migration ausente: ' . $migration);
    $pdo->exec($sql);
}
pg18Ok($pdo->query("SELECT current_setting('server_version_num')::int >= 180000")->fetchColumn(), 'execucao usa PostgreSQL 18');
pg18Ok($pdo->query("SELECT to_regclass('public.atualizacao_automatica_config') IS NOT NULL AND to_regclass('public.atualizacao_automatica_ciclos') IS NOT NULL AND to_regclass('public.atualizacao_automatica_resultados') IS NOT NULL AND to_regclass('public.atualizacao_automatica_pendencias') IS NOT NULL")->fetchColumn(), 'migrations 012, 013 e 015 sao compativeis');

final class Pg18UpdaterFake implements AtualizacaoAutomaticaSistemaUpdater {
    public array $sistemas = [];
    public array $falhar = [];
    public function atualizar(array $sistema): array {
        $id = (int)$sistema['sistema_id'];
        $this->sistemas[] = $id;
        if (in_array($id, $this->falhar, true)) throw new RuntimeException('falha isolada');
        return ['status'=>'atualizado','vencimento_novo'=>'2027-01-01','elegivel_novamente_em'=>'+24 hours'];
    }
}

function inserirCliente(PDO $pdo, string $nome, int $sistemas=1): int {
    $stmt=$pdo->prepare("INSERT INTO clientes(nome,marcador) VALUES (:nome,'preservar') RETURNING id");
    $stmt->execute(['nome'=>$nome]); $id=(int)$stmt->fetchColumn();
    $sis=$pdo->prepare("INSERT INTO sistemas(cliente_id,modelo_id,usuario,senha,vencimento,marcador) VALUES (:cliente,1,'usuario-falso','senha-falsa',CURRENT_DATE,'preservar')");
    for($i=0;$i<$sistemas;$i++) $sis->execute(['cliente'=>$id]);
    return $id;
}
function agendar(PDO $pdo, int $hora, int $versao): void {
    $stmt=$pdo->prepare("UPDATE atualizacao_automatica_config SET ativada=TRUE,ativado_em=clock_timestamp(),desativado_em=NULL,proxima_execucao=((CURRENT_DATE - INTERVAL '1 day') + (:hora||' hours')::interval) AT TIME ZONE 'America/Sao_Paulo',versao_ciclo=:versao WHERE id=1");
    $stmt->execute(['hora'=>$hora,'versao'=>$versao]);
}

for($i=1;$i<=26;$i++) inserirCliente($pdo, 'Ana '.$i, $i===1?2:1);
inserirCliente($pdo,'Bruno'); inserirCliente($pdo,'Ícaro'); inserirCliente($pdo,'Joao');
inserirCliente($pdo,'Katia'); inserirCliente($pdo,'Lucas'); inserirCliente($pdo,'Quezia');
inserirCliente($pdo,'Rafael'); inserirCliente($pdo,'Úrsula'); inserirCliente($pdo,'Vitor');
inserirCliente($pdo,'Xavier'); inserirCliente($pdo,'Yara'); inserirCliente($pdo,'123 Teste');

agendar($pdo,0,1); $updater=new Pg18UpdaterFake();
$resultado=(new AtualizacaoAutomaticaExecutor(new AtualizacaoAutomaticaPdoStore($pdo),$updater))->executarUmCiclo();
pg18Ok($resultado['processados']===25, 'limite de 25 clientes distintos');
pg18Ok(count($updater->sistemas)===26, 'todos os sistemas dos 25 clientes selecionados');
pg18Ok((int)$pdo->query("SELECT COUNT(*) FROM atualizacao_automatica_pendencias WHERE motivo='excedente'")->fetchColumn()>=2, 'excedentes e nome numerico entram em pendencias');
pg18Ok((int)$pdo->query('SELECT COUNT(DISTINCT cliente_id) FROM atualizacao_automatica_resultados')->fetchColumn()===25, 'historico registra clientes processados');
pg18Ok((int)$pdo->query('SELECT COUNT(*) FROM atualizacao_automatica_resultados')->fetchColumn()===26, 'historico registra cada sistema');
pg18Ok((int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE marcador='preservar'")->fetchColumn()===38 && (int)$pdo->query("SELECT COUNT(*) FROM sistemas WHERE marcador='preservar'")->fetchColumn()===39, 'dados e vinculos existentes sao preservados');

foreach ([8=>['I','J'],9=>['K','L'],14=>['Q','R'],17=>['U','V'],19=>['X','Y']] as $hora=>$iniciais) {
    foreach($iniciais as $inicial) pg18Ok(str_contains(AtualizacaoAutomaticaGrade::grupoDaHora($hora),$inicial), "agrupamento {$inicial} na hora {$hora}");
}
pg18Ok(AtualizacaoAutomaticaGrade::inicialNormalizada('Ágata')==='A' && AtualizacaoAutomaticaGrade::inicialNormalizada('Ícaro')==='I', 'acentos sao normalizados');

agendar($pdo,21,2); $recuperacao=new Pg18UpdaterFake();
$rec=(new AtualizacaoAutomaticaExecutor(new AtualizacaoAutomaticaPdoStore($pdo),$recuperacao))->executarUmCiclo();
pg18Ok($rec['processados']>=1 && $rec['processados']<=25, 'recuperacao processa pendencias com limite de 25');

$pdo->exec("UPDATE atualizacao_automatica_config SET ativada=FALSE,desativado_em=clock_timestamp(),proxima_execucao=NULL");
$inativo=(new AtualizacaoAutomaticaExecutor(new AtualizacaoAutomaticaPdoStore($pdo),new Pg18UpdaterFake()))->executarUmCiclo();
pg18Ok($inativo['estado']==='desativada', 'desativacao impede novo ciclo');
$repo=new AtualizacaoAutomaticaConfigRepository(new AtualizacaoAutomaticaPdoDatabase($pdo));
$reativado=$repo->ativar(1)['estado'];
pg18Ok($reativado['ativada']===true && $reativado['versao_ciclo']===3, 'reativacao cria nova versao sem recuperar horarios perdidos');

$pdo->exec("UPDATE atualizacao_automatica_config SET proxima_execucao=clock_timestamp()-INTERVAL '1 minute'");
$store1=new AtualizacaoAutomaticaPdoStore($pdo); $primeira=$store1->reservarCiclo();
$segundaPdo = new PDO($dsn, $usuario, $senha, $opcoesPdo);
$segunda=(new AtualizacaoAutomaticaPdoStore($segundaPdo))->reservarCiclo();
pg18Ok($primeira['estado']==='reservado' && $segunda['estado']!=='reservado', 'duas reservas nao processam o mesmo ciclo');
$pdo->exec("INSERT INTO atualizacao_automatica_ciclos(versao_ciclo,agendado_para,tipo,grupo,iniciado_em) VALUES (999,clock_timestamp()-INTERVAL '2 hours','grade','A',clock_timestamp()-INTERVAL '2 hours')");
$pdo->exec("UPDATE atualizacao_automatica_config SET proxima_execucao=clock_timestamp()-INTERVAL '1 minute'");
(new AtualizacaoAutomaticaPdoStore($pdo))->reservarCiclo();
pg18Ok($pdo->query("SELECT status='falhou' FROM atualizacao_automatica_ciclos WHERE versao_ciclo=999")->fetchColumn(), 'ciclo interrompido e recuperado como falha');

$falhaIds=$pdo->query('SELECT id FROM sistemas ORDER BY id DESC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec("DELETE FROM atualizacao_automatica_resultados; DELETE FROM atualizacao_automatica_pendencias");
$pdo->exec("UPDATE clientes SET nome='Z Teste' WHERE id IN (SELECT cliente_id FROM sistemas WHERE id IN (".implode(',',array_map('intval',$falhaIds))."))");
agendar($pdo,20,4); $falhas=new Pg18UpdaterFake(); $falhas->falhar=[(int)$falhaIds[0]];
$resFalha=(new AtualizacaoAutomaticaExecutor(new AtualizacaoAutomaticaPdoStore($pdo),$falhas))->executarUmCiclo();
pg18Ok($resFalha['falhas']===1 && count($falhas->sistemas)===2, 'falha individual nao interrompe outro cliente');
pg18Ok(is_file(dirname(__DIR__).'/atualizar_status_sistemas.php'), 'rotina manual permanece independente');
