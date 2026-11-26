<?php
declare(strict_types=1);

interface AtualizacaoAutomaticaExecutorStore {
    public function reservarCiclo(): array;
    public function listarSistemasElegiveis(bool $recuperacao): array;
    public function registrarPendencias(int $cicloId, string $grupo, array $clienteIds, string $motivo): void;
    public function automacaoAtiva(int $versaoCiclo): bool;
    public function resolverPendencia(int $clienteId): void;
    public function registrarResultado(int $cicloId, array $sistema, array $resultado): void;
    public function finalizarCiclo(int $cicloId, array $totais): void;
}

interface AtualizacaoAutomaticaSistemaUpdater {
    public function atualizar(array $sistema): array;
}

final class AtualizacaoAutomaticaGrade {
    private const GRUPOS = [0=>'A',1=>'B',2=>'C',3=>'D',4=>'E',5=>'F',6=>'G',7=>'H',8=>'IJ',9=>'KL',10=>'M',11=>'N',12=>'O',13=>'P',14=>'QR',15=>'S',16=>'T',17=>'UV',18=>'W',19=>'XY',20=>'Z',21=>'#',22=>'#',23=>'#'];

    public static function grupoDaHora(int $hora): string {
        if (!array_key_exists($hora, self::GRUPOS)) throw new InvalidArgumentException('Hora invalida');
        return self::GRUPOS[$hora];
    }
    public static function horarioDeRecuperacao(int $hora): bool { return $hora >= 21 && $hora <= 23; }
    public static function inicialNormalizada(string $nome): string {
        $texto = strtr(trim($nome), [
            'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','á'=>'A','à'=>'A','â'=>'A','ã'=>'A','ä'=>'A',
            'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','é'=>'E','è'=>'E','ê'=>'E','ë'=>'E',
            'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','í'=>'I','ì'=>'I','î'=>'I','ï'=>'I',
            'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','ó'=>'O','ò'=>'O','ô'=>'O','õ'=>'O','ö'=>'O',
            'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','ú'=>'U','ù'=>'U','û'=>'U','ü'=>'U',
            'Ç'=>'C','ç'=>'C','Ñ'=>'N','ñ'=>'N',
        ]);
        $inicial = strtoupper(substr($texto, 0, 1));
        return preg_match('/^[A-Z]$/', $inicial) === 1 ? $inicial : '#';
    }
    public static function pertenceAoGrupo(string $nome, string $grupo): bool {
        $inicial = self::inicialNormalizada($nome);
        return $grupo === '#' ? $inicial === '#' : str_contains($grupo, $inicial);
    }
}

final class AtualizacaoAutomaticaExecutor {
    public function __construct(private readonly AtualizacaoAutomaticaExecutorStore $store, private readonly AtualizacaoAutomaticaSistemaUpdater $updater, private readonly int $limiteClientes = 25) {
        if ($limiteClientes < 1) throw new InvalidArgumentException('Limite invalido');
    }

    public function executarUmCiclo(): array {
        $reserva = $this->store->reservarCiclo();
        if (($reserva['estado'] ?? '') !== 'reservado') return ['estado'=>(string)($reserva['estado'] ?? 'indisponivel')];
        $cicloId = (int)$reserva['ciclo_id'];
        $versao = (int)$reserva['versao_ciclo'];
        $grupo = (string)$reserva['grupo'];
        $recuperacao = ($reserva['tipo'] ?? '') === 'recuperacao';
        $linhas = $this->store->listarSistemasElegiveis($recuperacao);
        if (!$recuperacao) {
            $naoAlfabeticos = [];
            foreach ($linhas as $linha) {
                if (AtualizacaoAutomaticaGrade::inicialNormalizada((string)($linha['cliente_nome'] ?? '')) === '#') {
                    $naoAlfabeticos[(int)$linha['cliente_id']] = true;
                }
            }
            $this->store->registrarPendencias($cicloId, '#', array_keys($naoAlfabeticos), 'excedente');
        }
        $porCliente = $this->agruparPorCliente($linhas, $grupo, $recuperacao);
        $clienteIds = array_keys($porCliente);
        $selecionados = array_slice($clienteIds, 0, $this->limiteClientes);
        $excedentes = array_slice($clienteIds, $this->limiteClientes);
        $this->store->registrarPendencias($cicloId, $grupo, $selecionados, 'selecionado');
        $this->store->registrarPendencias($cicloId, $grupo, $excedentes, 'excedente');
        $processados = $atualizados = $falhas = 0;
        foreach ($selecionados as $posicao => $clienteId) {
            if (!$this->store->automacaoAtiva($versao)) {
                $this->store->registrarPendencias($cicloId, $grupo, array_slice($selecionados, $posicao), 'interrompido');
                break;
            }
            $clienteFalhou = false;
            foreach ($porCliente[$clienteId] as $sistema) {
                if (!$this->store->automacaoAtiva($versao)) { $clienteFalhou = true; break; }
                try { $resultado = $this->updater->atualizar($sistema); }
                catch (Throwable) { $resultado = ['status'=>'falha','codigo_falha'=>'UPDATER_EXCEPTION','detalhe_falha'=>'Falha isolada ao consultar o provedor','elegivel_novamente_em'=>'+1 hour']; }
                if (($resultado['status'] ?? 'falha') === 'falha') { $falhas++; $clienteFalhou = true; } else { $atualizados++; }
                $this->store->registrarResultado($cicloId, $sistema, $resultado);
            }
            $processados++;
            if ($clienteFalhou) $this->store->registrarPendencias($cicloId, $grupo, [$clienteId], 'falha');
            else $this->store->resolverPendencia($clienteId);
        }
        $totais = ['elegiveis'=>count($clienteIds),'processados'=>$processados,'atualizados'=>$atualizados,'falhas'=>$falhas];
        $this->store->finalizarCiclo($cicloId, $totais);
        return ['estado'=>'concluido','ciclo_id'=>$cicloId,'grupo'=>$grupo] + $totais;
    }

    private function agruparPorCliente(array $linhas, string $grupo, bool $recuperacao): array {
        $clientes = [];
        foreach ($linhas as $sistema) {
            if ($recuperacao
                && !in_array($sistema['pendente'] ?? false, [true, 1, '1', 't'], true)
                && AtualizacaoAutomaticaGrade::inicialNormalizada((string)($sistema['cliente_nome'] ?? '')) !== '#') continue;
            if (!$recuperacao && !AtualizacaoAutomaticaGrade::pertenceAoGrupo((string)($sistema['cliente_nome'] ?? ''), $grupo)) continue;
            $id = (int)($sistema['cliente_id'] ?? 0);
            if ($id > 0) $clientes[$id][] = $sistema;
        }
        return $clientes;
    }
}

final class AtualizacaoAutomaticaPdoStore implements AtualizacaoAutomaticaExecutorStore {
    private const FUSO = 'America/Sao_Paulo';
    public function __construct(private readonly PDO $pdo) {}

    public function reservarCiclo(): array {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("UPDATE atualizacao_automatica_ciclos SET status='falhou', finalizado_em=clock_timestamp() WHERE status='executando' AND iniciado_em < clock_timestamp() - INTERVAL '90 minutes'");
            $config = $this->pdo->query('SELECT ativada, proxima_execucao, versao_ciclo FROM atualizacao_automatica_config WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if (!is_array($config) || !in_array($config['ativada'], [true,1,'1','t'], true)) { $this->pdo->commit(); return ['estado'=>'desativada']; }
            $agendado = new DateTimeImmutable((string)$config['proxima_execucao']);
            if ($agendado > new DateTimeImmutable('now')) { $this->pdo->commit(); return ['estado'=>'aguardando_hora_cheia']; }
            $hora = (int)$agendado->setTimezone(new DateTimeZone(self::FUSO))->format('G');
            $tipo = AtualizacaoAutomaticaGrade::horarioDeRecuperacao($hora) ? 'recuperacao' : 'grade';
            $grupo = AtualizacaoAutomaticaGrade::grupoDaHora($hora);
            $stmt = $this->pdo->prepare('INSERT INTO atualizacao_automatica_ciclos (versao_ciclo,agendado_para,tipo,grupo) VALUES (:versao,:agendado,:tipo,:grupo) ON CONFLICT (versao_ciclo,agendado_para) DO NOTHING RETURNING id');
            $stmt->execute(['versao'=>(int)$config['versao_ciclo'],'agendado'=>$agendado->format(DateTimeInterface::ATOM),'tipo'=>$tipo,'grupo'=>$grupo]);
            $id = $stmt->fetchColumn();
            $this->pdo->exec("UPDATE atualizacao_automatica_config SET proxima_execucao=(date_trunc('hour', clock_timestamp() AT TIME ZONE 'America/Sao_Paulo') + INTERVAL '1 hour') AT TIME ZONE 'America/Sao_Paulo', atualizado_em=clock_timestamp() WHERE id=1");
            $this->pdo->commit();
            if ($id === false) return ['estado'=>'ciclo_ja_reservado'];
            return ['estado'=>'reservado','ciclo_id'=>(int)$id,'versao_ciclo'=>(int)$config['versao_ciclo'],'grupo'=>$grupo,'tipo'=>$tipo];
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function listarSistemasElegiveis(bool $recuperacao): array {
        $pendencia = $recuperacao ? 'LEFT JOIN atualizacao_automatica_pendencias p ON p.cliente_id=c.id' : '';
        $campoPendencia = $recuperacao ? '(p.cliente_id IS NOT NULL)' : 'FALSE';
        $sql = "SELECT s.id sistema_id,s.cliente_id,c.nome cliente_nome,{$campoPendencia} pendente,s.usuario,s.senha,s.m3u_url,s.vencimento vencimento_anterior,s.url,COALESCE(b.nome,m.nome,'SEM_PROVEDOR') provedor FROM sistemas s INNER JOIN clientes c ON c.id=s.cliente_id {$pendencia} LEFT JOIN modelos_sistemas m ON m.id=s.modelo_id LEFT JOIN provedor_blocos b ON b.id=m.bloco_id LEFT JOIN LATERAL (SELECT r.elegivel_novamente_em FROM atualizacao_automatica_resultados r WHERE r.sistema_id=s.id ORDER BY r.verificado_em DESC LIMIT 1) ultimo ON TRUE WHERE (ultimo.elegivel_novamente_em IS NULL OR ultimo.elegivel_novamente_em<=clock_timestamp()) ORDER BY c.nome,c.id,s.id";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function registrarPendencias(int $cicloId, string $grupo, array $clienteIds, string $motivo): void {
        if ($clienteIds === []) return;
        $stmt=$this->pdo->prepare('INSERT INTO atualizacao_automatica_pendencias (cliente_id,ciclo_origem_id,grupo_origem,motivo) VALUES (:cliente,:ciclo,:grupo,:motivo) ON CONFLICT (cliente_id) DO UPDATE SET ciclo_origem_id=EXCLUDED.ciclo_origem_id,grupo_origem=EXCLUDED.grupo_origem,motivo=EXCLUDED.motivo,atualizada_em=clock_timestamp()');
        foreach ($clienteIds as $id) $stmt->execute(['cliente'=>$id,'ciclo'=>$cicloId,'grupo'=>$grupo,'motivo'=>$motivo]);
    }
    public function automacaoAtiva(int $versaoCiclo): bool {
        $stmt=$this->pdo->prepare('SELECT 1 FROM atualizacao_automatica_config WHERE id=1 AND ativada=TRUE AND versao_ciclo=:versao'); $stmt->execute(['versao'=>$versaoCiclo]); return $stmt->fetchColumn()!==false;
    }
    public function resolverPendencia(int $clienteId): void { $stmt=$this->pdo->prepare('DELETE FROM atualizacao_automatica_pendencias WHERE cliente_id=:id'); $stmt->execute(['id'=>$clienteId]); }
    public function registrarResultado(int $cicloId, array $s, array $r): void {
        $proxima=(new DateTimeImmutable('now'))->modify((string)($r['elegivel_novamente_em']??'+24 hours'));
        $stmt=$this->pdo->prepare('INSERT INTO atualizacao_automatica_resultados (ciclo_id,cliente_id,sistema_id,provedor,status,vencimento_anterior,vencimento_novo,codigo_falha,detalhe_falha,elegivel_novamente_em) VALUES (:ciclo,:cliente,:sistema,:provedor,:status,:anterior,:novo,:codigo,:detalhe,:proxima)');
        $stmt->execute(['ciclo'=>$cicloId,'cliente'=>(int)$s['cliente_id'],'sistema'=>(int)$s['sistema_id'],'provedor'=>(string)$s['provedor'],'status'=>(string)($r['status']??'falha'),'anterior'=>$s['vencimento_anterior']??null,'novo'=>$r['vencimento_novo']??null,'codigo'=>$r['codigo_falha']??null,'detalhe'=>$r['detalhe_falha']??null,'proxima'=>$proxima->format(DateTimeInterface::ATOM)]);
    }
    public function finalizarCiclo(int $cicloId, array $t): void {
        $stmt=$this->pdo->prepare("UPDATE atualizacao_automatica_ciclos SET finalizado_em=clock_timestamp(),status='concluido',total_elegiveis=:elegiveis,total_processados=:processados,total_atualizados=:atualizados,total_falhas=:falhas WHERE id=:id"); $stmt->execute($t+['id'=>$cicloId]);
    }
}

final class AtualizacaoAutomaticaXtreamUpdater implements AtualizacaoAutomaticaSistemaUpdater {
    public function __construct(private readonly PDO $pdo, private readonly int $timeout=10, private readonly ?Closure $transporte=null, private readonly ?Closure $persistencia=null) {}
    public function atualizar(array $s): array {
        $consulta=$this->prepararConsulta($s);
        if ($consulta===null) return $this->falha('CREDENCIAIS_INCOMPLETAS','URL, usuario ou senha ausente');
        $res=$this->consultar((string)$consulta['api']);
        if (!$res['ok']) return $this->falha((string)$res['codigo'],(string)$res['detalhe']);
        $dados=json_decode((string)$res['corpo'],true); $exp=is_array($dados)?($dados['user_info']['exp_date']??null):null;
        if (!is_numeric($exp)||(int)$exp<=0) return $this->falha('RESPOSTA_SEM_VENCIMENTO','Resposta sem vencimento valido');
        $v=(new DateTimeImmutable('@'.(int)$exp))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d');
        $hoje=(new DateTimeImmutable('today',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $valores=['status'=>$v>=$hoje?'Active':'Expired','exp'=>(int)$exp,'v'=>$v,'id'=>(int)$s['sistema_id']];
        if ($this->persistencia!==null) { ($this->persistencia)($valores); }
        else { $stmt=$this->pdo->prepare('UPDATE sistemas SET status=:status,exp_date=:exp,vencimento=:v WHERE id=:id'); $stmt->execute($valores); }
        return ['status'=>$v===($s['vencimento_anterior']??null)?'sem_alteracao':'atualizado','vencimento_novo'=>$v,'elegivel_novamente_em'=>'+24 hours'];
    }

    private function prepararConsulta(array $s): ?array {
        $m3u=$this->uriValida($s['m3u_url']??null);
        $url=$this->uriValida($s['url']??null);
        $base=$this->baseUrl($url)??$this->baseUrl($m3u);
        $u=$this->textoValido($s['usuario']??null)??$this->parametro($m3u,'username');
        $p=$this->textoValido($s['senha']??null)??$this->parametro($m3u,'password');
        if ($base===null||$u===null||$p===null) return null;
        return ['api'=>$base.'/player_api.php?username='.rawurlencode($u).'&password='.rawurlencode($p)];
    }

    private function uriValida(mixed $valor): ?array {
        $texto=$this->textoValido($valor); if ($texto===null) return null;
        $partes=parse_url($texto);
        if ((!is_array($partes)||empty($partes['scheme']))&&str_contains($texto,'.')&&!str_contains($texto,' ')&&!str_starts_with($texto,'/')) $partes=parse_url('http://'.$texto);
        return is_array($partes)?$partes:null;
    }
    private function baseUrl(?array $uri): ?string {
        if ($uri===null||empty($uri['scheme'])||empty($uri['host'])) return null;
        $porta=isset($uri['port'])?':'.(int)$uri['port']:'';
        return strtolower((string)$uri['scheme']).'://'.$uri['host'].$porta;
    }
    private function parametro(?array $uri,string $nome): ?string {
        if ($uri===null||!isset($uri['query'])) return null; parse_str((string)$uri['query'],$query);
        return $this->textoValido($query[$nome]??null);
    }
    private function textoValido(mixed $valor): ?string {
        $texto=trim((string)($valor??'')); if ($texto==='') return null; $lower=strtolower($texto);
        return in_array($lower,['pendente','null'],true)?null:$texto;
    }
    private function consultar(string $api): array {
        if ($this->transporte!==null) return ($this->transporte)($api,$this->timeout);
        $ch=curl_init($api);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>$this->timeout,CURLOPT_TIMEOUT=>$this->timeout,CURLOPT_HTTPGET=>true]);
        $corpo=curl_exec($ch); $erro=curl_errno($ch); $http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($erro!==0) return ['ok'=>false,'codigo'=>$erro===CURLE_OPERATION_TIMEDOUT?'TIMEOUT_PROVEDOR':'ERRO_TRANSPORTE_PROVEDOR','detalhe'=>$erro===CURLE_OPERATION_TIMEDOUT?'Tempo limite ao consultar provedor':'Falha segura de transporte ao consultar provedor'];
        if ($http<200||$http>=300) return ['ok'=>false,'codigo'=>'HTTP_PROVEDOR','detalhe'=>'Provedor respondeu HTTP '.$http];
        if (!is_string($corpo)||$corpo==='') return ['ok'=>false,'codigo'=>'PROVEDOR_INDISPONIVEL','detalhe'=>'Provedor nao respondeu'];
        return ['ok'=>true,'corpo'=>$corpo];
    }
    private function falha(string $c,string $d): array { return ['status'=>'falha','codigo_falha'=>$c,'detalhe_falha'=>$d,'elegivel_novamente_em'=>'+1 hour']; }
}
