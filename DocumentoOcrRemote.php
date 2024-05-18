<?php
// Creado: MarcoM 20220205

/* This sample is in charge of obtaining extracted content from a remote server for documents in a local database,
then stores the content in the local database.
If no content can be obtained from the remote server, it sends batches of files for it to process them.
It also performs an API request to update information at a third party's database */

namespace App\Console\Commands;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Console\Command;
use App\Conservacion;
use App\OcrContent;
use App\OcrContentRemote;
use OCR;
use Storage;
use File;
use GuzzleHttp\Client as Guzzle;

class ConservacionOcrRemote extends Command
{
  protected $signature = 'cdo:conservacion-ocr-remote {id?} {size?} {--info}';
  protected $description = '
		Obtiene de servidor remoto el contenido extraído. Si no existe, envía al servidor remoto archivos de módulo Conservacion para proceso de OCR si no existe contenido local
		Si no se envía el argumento "id", procesa todos los que no hayan sido eliminados y se encuentren pendientes (ocr==null)
    El argumento "size" = [sm], [md] o [lg] realiza selecciones predefinidas basadas en el tamaño de los archivos.
    Nota: Para procesar por lotes, es indispensable enviar el valor "0" como primer argumento, de lo contrario, ignorará el segundo
		IMPORTANTE: Debe ejecutarse separado de scheduler de Laravel, SIN SUDO (Un cron del usuario PROPIETARIO de la carpeta LARAVEL). De lo contrario, NO REALIZARÁ LAS COPIAS
		Debe encontrarse habilitado en PRODUCCION pero NO en REMOTO
	';

  public function __construct()
  {
    parent::__construct();
    $this->path = Storage::disk('conservacion')->getDriver()->getAdapter()->getPathPrefix();
		$this->ocrextractor_path = Storage::disk('ocrextractor')->getDriver()->getAdapter()->getPathPrefix();
    $this->mega = 1000000; // Bytes
    $this->top = [
      "sm"=>["size"=>10*$this->mega, "limit"=>15],
      "md"=>["size"=>75*$this->mega, "limit"=>5],
      "lg"=>["size"=>200*$this->mega, "limit"=>4]
    ];
  }

  public function handle() {
    $conservacion = Conservacion::whereNull('ocr')->whereNull('deleted_at');
    if ($this->argument('id') != 0)
      $conservacion->where('id', $this->argument('id'));
    else {
      switch ($this->argument('size')) {
        case 'sm': $conservacion->where("size", ">", $this->mega)->where("size", "<=", $this->top["sm"]["size"])->limit($this->top["sm"]["limit"]); break;
        case 'md': $conservacion->where("size", ">", $this->top["sm"]["size"])->where("size", "<=", $this->top["md"]["size"])->limit($this->top["md"]["limit"]); break;
        case 'lg': $conservacion->where("size", ">", $this->top["md"]["size"])->where("size", "<=", $this->top["lg"]["size"])->limit($this->top["lg"]["limit"]); break;
        default: $conservacion->where("size", "<=", $this->mega)->limit(155); break;
      }
    }
    $conservacion = $conservacion->get();
    if ($this->option("info")){
      $this->info("Se procesarán ".$conservacion->count()." archivos:");
      dd($conservacion->pluck('id'));
    }


    foreach($conservacion as $conservacion){
  		$remotes = OcrContentRemote::where('parentable_id', $conservacion->id)->get();
      $this->info("Buscando contenido remoto para ".$conservacion->id);
  		if (!empty($remotes) && $remotes->count()){
        foreach($remotes as $remote){
          $remote->uid = $conservacion->uid;
          OcrContent::insert($remote->toArray());
          $remote->delete();
        }
        //Actualizar Panel
        $guzzle = new Guzzle;
  			$response = $guzzle->request(
          'POST',config('cdo.api.url')."/conservacion/".$conservacion->id."/update",
          [
            'auth' => [config('cdo.app.clientid'),config('cdo.app.secret')],
            'verify' => false,
            'multipart' => [
              ['name' => 'ocr', 'contents' => 1],
              ['name' => 'ocrprocessing', 'contents' => 0]
            ]
          ]
        );
  			$result = json_decode($response->getBody()->getContents(),false);

        //Actualizar Local
  			$conservacion->ocr = true;
        $conservacion->unset('ocrprocessing');
        $conservacion->save();

        $this->info($conservacion->id." importado a local");
  		}else{
        $this->info("-No existe contenido remoto para ".$conservacion->id);
        $status = 0;
        $file = $this->path.$conservacion->uid."/".$conservacion->apptokenid."/".$conservacion->id;
        if (!$conservacion->ocrprocessing){
          if (!File::exists($file)){
            $documento = new \App\Http\Controllers\CDO\CDOConservacionController;
            $documento->getFile($conservacion);
          }
          exec('sshpass -p "'.config('cdo.ocrextractor.pass').'" scp '.$file.' '.config('cdo.ocrextractor.user').'@'.config('cdo.ocrextractor.url').':'.$this->ocrextractor_path, $output, $status);
          if ($status==0){ // Si se copió con exito, entonces:
            // Actualizar Panel
            $guzzle = new Guzzle;
            $response = $guzzle->request(
              'POST',config('cdo.api.url')."/conservacion/".$conservacion->id."/update",
              [
                'auth' => [config('cdo.app.clientid'),config('cdo.app.secret')],
                'verify' => false,
                'multipart' => [
                  ['name' => 'ocrprocessing', 'contents' => 1]
                ]
              ]
            );
            $result = json_decode($response->getBody()->getContents(),false);

            //Actualizar Local
            $conservacion->ocrprocessing = true;
            $conservacion->save();

            $this->info("-Enviando ".$conservacion->id." a procesador remoto");
          }
        }

        // Restablecer. Si se ha intentado obtener sin éxito el contenido en multiples llamadas, significa que remoto no realizó la extracción o esta se eliminó de la colección remota antes de obtenerla
        $conservacion->ocrtries++;
        if ($conservacion->ocrtries > 10){
          $this->info("-Se superaron los intentos. Restableciendo ".$conservacion->id);
          $conservacion->unset('ocrtries');
          $conservacion->unset('ocrprocessing');
        }

        $conservacion->save();
      }
    }
  }
}
