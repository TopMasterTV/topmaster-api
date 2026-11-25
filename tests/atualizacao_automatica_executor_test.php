<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/atualizacao_automatica_executor.php';

function ok(bool $c,string $m):void{if(!$c)throw new RuntimeException('FALHA: '.$m);fwrite(STDOUT,'OK: '.$m.PHP_EOL);}
final class StoreFake implements AtualizacaoAutomaticaExecutorStore{
 public array $reserva=['estado'=>'reservado','ciclo_id'=>10,'versao_ciclo'=>1,'grupo'=>'A','tipo'=>'grade'];public array $sistemas=[],$pendencias=[],$resultados=[],$resolvidas=[];public ?array $totais=null;public array $ativa=[];private int $checks=0;
 public function reservarCiclo():array{return $this->reserva;}
 public function listarSistemasElegiveis(bool $r):array{return $this->sistemas;}
 public function registrarPendencias(int $c,string $g,array $ids,string $m):void{foreach($ids as $id)$this->pendencias[$id]=$m;}
 public function automacaoAtiva(int $v):bool{return $this->ativa[$this->checks++]??true;}
 public function resolverPendencia(int $id):void{$this->resolvidas[]=$id;unset($this->pendencias[$id]);}
 public function registrarResultado(int $c,array $s,array $r):void{$this->resultados[]=[$s,$r];}
 public function finalizarCiclo(int $c,array $t):void{$this->totais=$t;}
}
final class UpdaterFake implements AtualizacaoAutomaticaSistemaUpdater{public array $ids=[],$falhas=[];public function atualizar(array $s):array{$id=(int)$s['sistema_id'];$this->ids[]=$id;if(in_array($id,$this->falhas,true))throw new RuntimeException();return ['status'=>'atualizado'];}}
function sis(int $cliente,int $sistema,string $nome):array{return ['cliente_id'=>$cliente,'sistema_id'=>$sistema,'cliente_nome'=>$nome,'provedor'=>'qualquer'];}

foreach([0=>'A',1=>'B',2=>'C',8=>'IJ',9=>'KL',14=>'QR',17=>'UV',19=>'XY',20=>'Z',21=>'#',23=>'#']as$h=>$g)ok(AtualizacaoAutomaticaGrade::grupoDaHora($h)===$g,"grade da hora {$h}");
ok(AtualizacaoAutomaticaGrade::inicialNormalizada('Álvaro')==='A','normaliza acento');
ok(AtualizacaoAutomaticaGrade::inicialNormalizada('Çesar')==='C','normaliza cedilha');
ok(AtualizacaoAutomaticaGrade::inicialNormalizada('123 Cliente')==='#','nome numerico vai para recuperacao');

$s=new StoreFake();$s->reserva=['estado'=>'desativada'];$u=new UpdaterFake();$r=(new AtualizacaoAutomaticaExecutor($s,$u))->executarUmCiclo();ok($r['estado']==='desativada'&&$u->ids===[],'desativada impede consultas');

$s=new StoreFake();for($i=1;$i<=26;$i++){$s->sistemas[]=sis($i,$i*10,'Ana '.$i);$s->sistemas[]=sis($i,$i*10+1,'Ana '.$i);} $u=new UpdaterFake();$r=(new AtualizacaoAutomaticaExecutor($s,$u))->executarUmCiclo();ok($r['processados']===25,'limita 25 clientes distintos');ok(count($u->ids)===50,'processa todos os sistemas dos 25 clientes');ok(($s->pendencias[26]??null)==='excedente','registra cliente excedente');

$s=new StoreFake();$s->sistemas=[sis(1,1,'Ana'),sis(2,2,'Bruno'),sis(3,3,'Ágata')];$u=new UpdaterFake();(new AtualizacaoAutomaticaExecutor($s,$u))->executarUmCiclo();ok($u->ids===[1,3],'seleciona letra e acento do horario');

$s=new StoreFake();$s->sistemas=[sis(1,1,'123 Cliente')];$u=new UpdaterFake();(new AtualizacaoAutomaticaExecutor($s,$u))->executarUmCiclo();ok(($s->pendencias[1]??null)==='excedente'&&$u->ids===[],'encaminha nome nao alfabetico para recuperacao');

$s=new StoreFake();$s->reserva=['estado'=>'reservado','ciclo_id'=>10,'versao_ciclo'=>1,'grupo'=>'#','tipo'=>'recuperacao'];$pendente=sis(1,1,'Bruno');$pendente['pendente']=true;$s->sistemas=[$pendente,sis(2,2,'123'),sis(3,3,'Carlos')];$u=new UpdaterFake();(new AtualizacaoAutomaticaExecutor($s,$u))->executarUmCiclo();ok($u->ids===[1,2],'recuperacao processa pendencia ou nome nao alfabetico');

$s=new StoreFake();$s->sistemas=[sis(1,1,'Ana'),sis(2,2,'Ana')];$s->ativa=[true,true,false];$u=new UpdaterFake();$r=(new AtualizacaoAutomaticaExecutor($s,$u))->executarUmCiclo();ok($u->ids===[1],'desativacao durante ciclo impede nova consulta');ok(($s->pendencias[2]??null)==='interrompido','preserva pendencia legitima interrompida');

$s=new StoreFake();$s->sistemas=[sis(1,1,'Ana'),sis(2,2,'Ana')];$u=new UpdaterFake();$u->falhas=[1];$r=(new AtualizacaoAutomaticaExecutor($s,$u))->executarUmCiclo();ok($u->ids===[1,2]&&$r['falhas']===1,'falha individual nao interrompe ciclo');ok(count($s->resultados)===2,'registra resultados individuais');

$fonte=file_get_contents(dirname(__DIR__).'/atualizacao_automatica_executor.php');ok(!str_contains($fonte,"'GP' => 5")&&!str_contains($fonte,'limitesPorProvedor'),'remove limites separados por provedor');
