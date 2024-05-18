<?php
// Creado: MarcoM 20230402
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\ExpedienteController;
use App\Documento;
use App\Expediente;
use App\User;
use Mail;
use Storage;
use MongoDB\BSON\ObjectID;
use ZipArchive;
use File;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client as Guzzle;

class Offboarding extends Command
{
  public function __construct()
  {
    parent::__construct();
    $this->tmp_path = Storage::disk('tmp')->getDriver()->getAdapter()->getPathPrefix(); //sys_get_temp_dir()."/"; // PHP esta usando su propio /tmp (/tmp/systemd-private-...tmp/)
    $this->documento_path = Storage::disk("documento")->getDriver()->getAdapter()->getPathPrefix();
    $this->contrato_path = Storage::disk('contrato')->getDriver()->getAdapter()->getPathPrefix();
    $this->bundle_path = Storage::disk('bundle')->getDriver()->getAdapter()->getPathPrefix();
  }

  protected $signature = 'concerteza:offboarding {rolesid} {--expedientes} {--export} {--apply}';
  protected $description = '
    MUY IMPORTANTE: La primera ejecución debe ser sin --apply para identificar los documentos en BD y generar entregables
    Default:
      * Procesa solo documentos (No expedientes).
      * Genera CSVs correspondientes
    Option --expedientes: Procesa solo expedientes (No documentos en general).
    Option --export:
      * Asigna atributo "offboarding_id" a los registros correspondientes.
      * Genera ademas un zip con los archivos entregables correspondientes (Con opcion --expedientes no exporta los documentos originales).
    Option --apply: Unicamente realiza eliminación de los registros correspondientes en "documentos" o "expedientes" (Eliminación en cascada implementada en cada modelo).
  ';

  public function handle()
  {
    $expedientes = $usuarios = [];
    $offboarding_id = (string) new ObjectID();
    $documentos = Documento::whereNull('deleted_at')
      ->where('roles_id', (int) $this->argument('rolesid'));
    $file_path = $this->bundle_path."OffboardingId_"."$offboarding_id"."/";

    if ($this->option('expedientes')) $documentos = $documentos->whereNotNull('expediente_id');
    else $documentos = $documentos->whereNull('expediente_id');

// REMOVER LIMIT AL FINALIZAR PRUEBAS!!!
    $documentos = $documentos
//      ->limit(5)
      ->get();

    $doc_controller = new DocumentoController;

    $this->info("Offboarding Id is:".$offboarding_id);

    if (!File::exists($file_path)) File::makeDirectory($file_path, 0775, true);

    $csv_file = ($file_path."documentos.csv");
    $csv_f = fopen($csv_file, 'w');
    $csv_fields = ["id", "documento_types_id", "expediente_id", "filename", "uid", "roles_id", "ip", "documento_status_id", "size", "hash", "pages", "created_at", "cdo_contratos_id", "constancia.id"];
    fputcsv($csv_f, $csv_fields, ",");

    $tot_documentos = 0;
    foreach ($documentos as $documento) {
      $tot_documentos++;
      // Empaquetado de documentos {
      $this->info("Modificando documento ID: ".$documento->_id);
      $documento->offboarding_id = $offboarding_id;
      if ($this->option('export')) $documento->save();

      if ($this->option('export') && !$this->option('expedientes')){
        $is_pdf = false;

        if (!empty($documento->cdo_contratos_id))
          $file = $this->cdoGetContrato($documento);
        else {
          try {
            $file = $doc_controller->downloadBundle($documento->_id, true);
          }catch (\Exception $e){
            $file = $this->documento_path."/".$documento->_id; /* Si no obtiene nada de DocumentoController, devuelve el documento original*/
            $is_pdf = true;
          }
        }

        $folder = $documento->filename."_".$documento->_id;
        if (is_file($file)){
          if(!File::exists($file_path.$folder)) File::makeDirectory($file_path.$folder, 0775, true);
          foreach(explode("/", $documento->folder) as $dir) {
            $folder .= $dir."/";
            if(!File::exists($file_path.$folder)) File::makeDirectory($file_path.$folder, 0775, true);
          }
          File::put($file_path.$folder.$documento->_id.($is_pdf? ".pdf" : ".zip"), file_get_contents($file));
        }else $this->info("El documento ".$documento->_id." no existe en disco");
      }

      fputcsv($csv_f, $documento->only($csv_fields), ",");
      // } Empaquetado de documentos

      // Lista Usuarios
      if (!empty($documento->users)) $usuarios[$documento->users->id] = $documento->users;

      if ($this->option('expedientes')){
        $this->info("Modificando expediente ID: ".$documento->expediente->_id);
        $documento->expediente->offboarding_id = $offboarding_id;
        $expedientes[$documento->expediente->_id] = $documento->expediente;
        if ($this->option('export')) $documento->expediente->save();
      }

      if ($this->option('apply') && !empty($documento->offboarding_id)){
        if (!$this->option('expedientes')) $this->info("Eliminando documento: ".$documento->_id . ": " . $doc_controller->destroy($documento->_id, true));
        if ($this->option('expedientes')){
          $exp_controller = new ExpedienteController;
          $this->info("Eliminando expediente: ".$documento->expediente->_id . ": " . $exp_controller->destroy($documento->expediente->_id));
        }
      }
    }

    fclose($csv_f);

    // Empaquetado de expedientes {
    if ($this->option('expedientes')){ // CSV Expedientes
      $csv_file = ($file_path."expedientes.csv");
      $csv_f = fopen($csv_file, 'w');
      $csv_fields = ["id", "name", "custom_id", "status", "uid", "roles_id", "created_at", "hash_signed", "constancia.id"];
      fputcsv($csv_f, $csv_fields, ",");
      foreach ($expedientes as $expediente){
        if ($this->option('export')){
          if (!empty($expediente->constancia["id"])){
            $exp_controller = new ExpedienteController;
            $file = $exp_controller->cdoGetContrato($expediente->id, true);
            File::put($file_path.$expediente->id.".zip", file_get_contents($file));
          }else $this->info ("Expediente ".$expediente->id." sin constancia. No se ha exportado");
        }
        fputcsv($csv_f, $expediente->only($csv_fields), ",");
      }

      fclose($csv_f);
    }
    // } Empaquetado de expedientes

    // Listado Usuarios
    $csv_file = ($file_path."usuarios.csv");
    $csv_f = fopen($csv_file, 'w');
    $csv_fields = ["id", "first_name", "last_name", "email", "company"];
    fputcsv($csv_f, $csv_fields, ",");
    foreach ($usuarios as $usuario)
      fputcsv($csv_f, $usuario->only($csv_fields), ",");
    fclose($csv_f);

    if ($this->option('apply')) $this->info("Se eliminaron ". $tot_documentos." documentos y ".count($expedientes)." expedientes");
    else $this->info("Dry test. Para eliminar ".$tot_documentos." documentos y ".count($expedientes)." expedientes, ejecutar con la opción --apply.");
  }

  public function cdoGetContrato($documento)
  {
    $file = $this->contrato_path . "/" . $documento->_id;
    $credentials = [config('cdo.keys.client_id'), config('cdo.keys.secret')];
    $error = false;

    if (!File::exists($file)) {
      if (!File::exists($this->contrato_path)) File::makeDirectory($this->contrato_path, 0775, true);
      $guzzle = new Guzzle;
      try {
        $response = $guzzle->request('GET', config('cdo.info.api_url') . "/contrato/" . $documento->cdo_contratos_id . "/descargar-certificado", [
          'auth' => $credentials,
          'verify' => false
        ]);
      } catch (\Exception $e){ $error = true; }
      if ($error){
        try {
          $response = $guzzle->request('GET', config('cdo.info.api_url') . "/contrato/" . $documento->cdo_contratos_id . "/descargar", [
            'auth' => $credentials,
            'verify' => false
          ]);
        } catch (\Exception $e){ $error = true; }
      }
      File::put($file, $response->getBody()->getContents());
      return $file;
    } else return $file;
  }
}

