<?php
// Creado: MarcoM 20220127

namespace App\Console\Commands;

use Symfony\Component\Process\Process;
	use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Console\Command;
use App\Documento;
use App\OcrContent;
use OCR;
use Storage;
use File;

class DocumentoOcr extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'concerteza:documento-ocr {id?}';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = '
		Extrae contenido de PDFs de archivos en módulo Documentos.
		Si no se envía el argumento "id", procesa todos los que no hayan sido eliminados y se encuentren pendientes (ocr==null)
	';

  /**
   * Create a new command instance.
   *
   * @return void
   */
  public function __construct()
  {
    parent::__construct();
    $this->path = Storage::disk('documento')->getDriver()->getAdapter()->getPathPrefix();
  }

  /**
   * Execute the console command.
   *
   * @return mixed
   */
  public function handle() {
    if($this->argument('id') != null)
      $documentos = Documento::where('_id', $this->argument('id'))->whereNull('ocr')->get();
    else {
      $documentos = Documento::whereNull('ocr')
        ->whereNull('deleted_at')
        ->get();
    }
    foreach($documentos as $documento){
      $ocr = $this->extract($documento);
      if ($ocr){
         $documento->ocr = $ocr;
         $documento->save();
      }
    }
  }

  public function extract($documento){
    $error = "";
    try { // Si el PDF es legible (https://github.com/spatie/pdf-to-text). Postcardware!
      $ocr = $this->updateDb($documento, (new \Spatie\PdfToText\Pdf())
        ->setPdf($this->path.$documento->id)->text());
    }catch (\Exception $e) {
      $error=$e;
    }

    if (!empty($error) || ($ocr===false)){ // Si el PDF es solo imagen (https://github.com/spatie/pdf-to-image). Postcardware!
      try {
        $pdf = new \Spatie\PdfToImage\Pdf($this->path.$documento->id);
        $num_pags=$pdf->getNumberOfPages();
        for ($i=1; $i<=$num_pags; $i++){
          $this->info("Procesando página ".$i." de ".$num_pags);
          $pdf->setPage($i)
            ->saveImage(sys_get_temp_dir()."/".$documento->id."_".$i);
          $this->updateDb($documento, OCR::scan(sys_get_temp_dir()."/".$documento->id."_".$i), $i); // Se actualiza cada pagina para prevenir agotamiento de memoria
          File::delete(sys_get_temp_dir()."/".$documento->id."_".$i);
        }
      }catch (\Exception $e){
        return false;
      }
    }
    return true;
  }

  public function updateDb($documento, $contenido, $page=1){
    if($contenido!=""){
      $ocr = new OcrContent;
      $ocr->parentable_id = $documento->id;
			$ocr->parentable_type = "App\\Documento";
			$ocr->parent = "documento"; // Si. Singular
      $ocr->uid = $documento->uid;
      $ocr->roles_id = $documento->roles_id;
      $ocr->ocr = $contenido;
			$ocr->page = $page;
      $ocr->save();
    }else return false;
  }
}

