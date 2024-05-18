<?php
// Creado: MarcoM 20190218

/* This sample is part of the final stage of document signing and is in charge of digitally signing a document,
obtaining a certificate from an oficial entity, generating deliverables and finally, sending a download link
to the involved parties. Alternatively, it creates a copy of the original document that includes visual representations
of signatures, which serves as a preview  */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\FirmaAvanzadaController;
use App\Http\Controllers\NotificadoController;
use App\Documento;
use App\Firmante;
use App\User;
use Storage;
use setasign\Fpdi\Fpdi;
use File;
use Carbon\Carbon;
use App\Redirect;
use Intervention\Image\ImageManagerStatic as Image;
use QrCode;

class DocumentoSign extends Command
{
  public function __construct()
  {
    parent::__construct();
    $this->module = "documento";
    $this->tmp_path = sys_get_temp_dir() . "/"; // PHP esta usando su propio /tmp (/tmp/systemd-private-...tmp/)
    $this->documento_path = Storage::disk($this->module)->getDriver()->getAdapter()->getPathPrefix();
    $this->public_path = Storage::disk('public')->getDriver()->getAdapter()->getPathPrefix();
    $this->vars_sign = [
      'dimensions' => ['x' => 794, 'y' => 1122], // Array sets the X, Y dimensions in pt
      'box_wh' => ["w" => 100, "h" => 63],
      'signature_wh' => ['w' => 75, 'h' => 47], // Solo se envía el ancho en la función, de modo que no se distorsione la firma
      'margin' => 19,
      'font_size' => 6,
    ];
  }

  protected $signature = 'concerteza:documento-sign {id} {--preview}';
  protected $description = 'Firma el documento solicitado y envía enlace para descarga de documento firmado.
  La opción --preview devuelve el documento con las casillas donde se plasmarían las firmas pero no realiza
  actualizaciones en base de datos';

  public function handle()
  {
    $documento = Documento::where('_id', $this->argument("id"))
      ->whereNull('deleted_at')
      ->with([
        'users', 'status', 'type', 'notificados', 'corporacion',
        'firmantes' => function ($query) {
          $query
            ->whereNull('deleted_at')
            ->orderBy('cert_required')
            ->orderBy('_id', 'desc') // Descendente por la manera en que se plasmará en el documento
            ->with([
              'notification_types',
              'firmas' => function ($query) {
                $query
                  ->whereNotNull('date_confirmed')
                  ->whereNull('deleted_at')
                  ->orderBy('_id', 'desc'); // Descendente para tomar la ultima firma registrada en caso de que haya mas de una
              }
            ]);
        }
      ])
      ->first();

    if (!empty($documento->documento_status_id) && $documento->documento_status_id == 2) {
      $session = new DocumentoController;
      $source = $this->documento_path . $this->argument("id");
      $output = $this->documento_path . $this->argument("id") . ($this->option("preview") ? "_preview" : "_sign");
      if ($documento->firmantes->count() == Firmante::where('documentos_id', $documento->_id)->whereNull('deleted_at')->count()) {
        $margin = $this->vars_sign['margin'];
        foreach ($documento->firmantes as $firmante) {
          $firma = (!$this->option("preview") && !$firmante->cert_required) ? $firmante->firmas[0] : (object) ['signature' => "data:image/gif;base64,R0lGODlhAQABAIAAAMLCwgAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw=="];
          if ($firma) {
            try {
              $pdf = new FPDI('Portrait', 'pt', array($this->vars_sign['dimensions']['x'], $this->vars_sign['dimensions']['y']));
              $pagecount = $pdf->setSourceFile($source);

              for ($i = 1; $i <= $pagecount; $i++) {
                $tppl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tppl);
                $pdf->AddPage($size["orientation"], [$size["width"], $size["height"]]);
                $pdf->useTemplate($tppl); // X, Y, Width in pt

                // MarcoM20220909. Info QR. Se plasmará tanto en preview como en final
                $qr_size = 35;
                $x = ($size['width'] - $qr_size - 5);
                $y = 5;
                $pdf->Image("data://text/plain;base64," . base64_encode(QrCode::format('png')->size($qr_size * 3 /*Para mayor resolucion*/)->color(52, 110, 181)->style('square')->generate(config('cdo.info.front_url') . "/info/" . $documento->_id)), $x, $y, $qr_size, $qr_size, "png"); // Info QR
                $pdf->SetFont('Helvetica', '', $this->vars_sign['font_size']); // Font Name, Font Style (eg. 'B' for Bold), Font Size
                $pdf->SetTextColor(52, 110, 181); // RGB
                $pdf->SetXY(0, $y); // X start, Y start in pt
                $pdf->Write(0, config('cdo.info.front_url') . "/info/" . $documento->_id, 0, 20);

                if (
                  !empty($firmante->signature_coords[0])
                  && ($firmante->signature_coords[0]['x'] != null
                    || $firmante->signature_coords[0]['y'] != null
                  )
                ) { // Verifica que al menos el primer elemento no contenga elementos vacios (Para evitar entrar si por ejemplo "signature_coords" : [[]])
                  foreach ($firmante->signature_coords as $coords) {
                    // MarcoM20220919. Sobreescribir rúbrica según el tipo elegido {
                    $file_path = $this->tmp_path . $this->argument("id");
                    $image = $firma->signature;

                    if (!$this->option("preview") && (!empty($coords['sign_type']) && $coords['sign_type'] != 0)) {
                      $image = $firma->{"sign_type_" . $coords['sign_type']};
                      $file_path .= "_" . $coords['sign_type'];
                    }
                    $pieces = explode(',', $image);
                    $encodedImg = $pieces[1];
                    $file_path .= ".png";

                    file_put_contents($file_path, base64_decode($encodedImg));
                    $img = Image::make($file_path);
                    $img_w = $img->width();
                    $img_h = $img->height();
                    // MarcoM20220919. Sobreescribir rúbrica según el tipo elegido }

                    if ($coords['page'] == $i) {
                      // Aplicamos regla de 3 para cuadrar las dimensiones obtenidas del front contra las de la librería
                      $x = ($size['width'] * $coords['x']) / $coords['width']; // Ancho calculado por FPDI, multiplicado por x_coords, entre ancho obtenido del front
                      $y = $size["height"] + ($size['height'] * $coords['y']) / $coords['height']; // Alto calculado por FPDI, multiplicado por y_coords, entre alto obtenido del front
                      if ($y < ($this->vars_sign['font_size'] * 4)) $y = ($this->vars_sign['font_size'] * 4);
                      if ($y > $size['height'] - $this->vars_sign['box_wh']['h']) $y = $size['height'] - $this->vars_sign['box_wh']['h'];
                      if ($x < 0) $x = $this->vars_sign['margin'];
                      if ($x > $size['width'] - $this->vars_sign['box_wh']['w']) $x = $size['width'] - $this->vars_sign['box_wh']['w'] - $this->vars_sign['margin'];
                      //$coords = $this->vars_sign['signature_wh']; // POR EL MOMENTO SE SOBREESCRIBEN LAS DIMENSIONES DE LA FIRMA
                      if ($this->option("preview") || (!$this->option("preview") && !$firmante->cert_required))
                        $pdf->Image($this->public_path . "img/SignatureBox.png", $x, $y, $this->vars_sign['box_wh']["w"], $this->vars_sign['box_wh']["h"]); // Signature Box
                      if (!$this->option("preview") && !$firmante->cert_required) {
                        $pdf->Image( // Signature. X start, Y start, X width, Y height in pt
                          $file_path,
                          $x + (($img_w < $img_h) ? $this->vars_sign['signature_wh']['w'] - 10 - ($img_w / $img_h) * $this->vars_sign['signature_wh']['h'] : 15),
                          $y + (($img_w > $img_h) ? $this->vars_sign['signature_wh']['h'] - 10 - ($this->vars_sign['signature_wh']['h'] * $img_h / $img_w) : 7),
                          ($img_w >= $img_h) ? $this->vars_sign['signature_wh']['w'] : null,
                          ($img_w < $img_h) ? $this->vars_sign['signature_wh']['h'] : null
                        );
                      }
                      $pdf->SetFont('Helvetica', '', $this->vars_sign['font_size']); // Font Name, Font Style (eg. 'B' for Bold), Font Size
                      $pdf->SetTextColor(0, 104, 237); // RGB
                      if ($this->option("preview") || (!$this->option("preview") && !$firmante->cert_required)) {
                        $y -= $this->vars_sign['font_size'] * 3; // Sube el comienzo del nombre del firmante un 80% de lo que mide la fuente
                        $pdf->SetXY($x, $y); // X start, Y start in pt
                        $pdf->Write(0, substr(utf8_decode(strtoupper($firmante->name)), 0, 20));

                        $y += $this->vars_sign['font_size'] * 1;
                        $pdf->SetXY($x, $y); // X start, Y start in pt
                        $pdf->Write(0, substr(utf8_decode(strtoupper($firmante->last_name)), 0, 20));
                      }

                      $y += $this->vars_sign['font_size'] * 1;
                      $pdf->SetXY($x, $y); // X start, Y start in pt
                      $pdf->SetFont('Helvetica', '', $this->vars_sign['font_size'] * 0.80); // Font Name, Font Style (eg. 'B' for Bold), Font Size
                      if (!$this->option("preview")) {
                        if (!$firmante->cert_required) $pdf->Write(0, utf8_decode(strtoupper($firma->_id)));
                      } else {
                        $pdf->Write(0, "P: " . $coords['page'] . ", X: " . $coords['x'] . ", Y: " . $coords['y'] . ", W: " . (int)$coords['width'] . ", H: " . (int)$coords['height']);
                      }
                    }
                    File::delete($file_path);
                  }
                } else {
                  $file_path = $this->tmp_path . $this->argument("id") . ".png"; // MarcoM20220926. Si no se proporcionaron signature_coords, debe procesarse como se hacía antes y poner las firmas al final del documento
                  $image = $firma->signature;
                  $pieces = explode(',', $image);
                  $encodedImg = $pieces[1];
                  file_put_contents($file_path, base64_decode($encodedImg));
                  $img = Image::make($file_path);
                  $img_w = $img->width();
                  $img_h = $img->height();

                  if ($i == $pagecount) {
                    $x = $size["width"] - $this->vars_sign['box_wh']["w"] - $margin;
                    $y = $size["height"] - $this->vars_sign['box_wh']["h"] - 5; // Sube el signaturebox 5 pt desde el final de la pagina
                    if ($this->option("preview") || (!$this->option("preview") && !$firmante->cert_required))
                      $pdf->Image($this->public_path . "img/SignatureBox.png", $x, $y, $this->vars_sign['box_wh']["w"], $this->vars_sign['box_wh']["h"]); // Signature Box
                    if (!$this->option("preview") && !$firmante->cert_required) {
                      $pdf->Image( // Signature. X start, Y start, X width, Y height in pt
                        $file_path,
                        $x + ($this->vars_sign['box_wh']['w'] * (($img_w < $img_h) ? 0.28 : 0.15)),
                        $y + ($this->vars_sign['box_wh']['w'] * (($img_w > $img_h) ? 0.2 : 0.1)),
                        ($img_w >= $img_h) ? $this->vars_sign['signature_wh']['w'] : null,
                        ($img_w < $img_h) ? $this->vars_sign['signature_wh']['h'] : null
                      );
                    }
                    $pdf->SetFont('Helvetica', '', $this->vars_sign['font_size']); // Font Name, Font Style (eg. 'B' for Bold), Font Size
                    $pdf->SetTextColor(0, 104, 237); // RGB

                    if ($this->option("preview") || (!$this->option("preview") && !$firmante->cert_required)) {
                      $y -= $this->vars_sign['font_size'] * 3; // Sube el comienzo del nombre del firmante un 80% de lo que mide la fuente
                      $pdf->SetXY($x, $y); // X start, Y start in pt
                      $pdf->Write(0, substr(utf8_decode(strtoupper($firmante->name)), 0, 20));

                      $y += $this->vars_sign['font_size'] * 1;
                      $pdf->SetXY($x, $y); // X start, Y start in pt
                      $pdf->Write(0, substr(utf8_decode(strtoupper($firmante->last_name)), 0, 20));
                    }

                    $y += $this->vars_sign['font_size'] * 1;
                    if (!$this->option("preview")) {
                      $pdf->SetXY($x, $y); // X start, Y start in pt
                      $pdf->SetFont('Helvetica', '', $this->vars_sign['font_size'] * 0.80); // Font Name, Font Style (eg. 'B' for Bold), Font Size
                      if (!$firmante->cert_required) $pdf->Write(0, utf8_decode(strtoupper($firma->_id)));
                    }
                  }
                }
              }

              $pdf->Output($output, "F");

              if (!$this->option("preview") && !$firmante->cert_required) $name_after = $session->portableSigner($output); // Crea archivo $output con sufijo "ed".
            } catch (\Exception $e) {
              $this->info($e->getMessage());
            }

            File::delete($file_path);
          } else $this->info('ui.documento.messages.fail.sign.no-firma');
          $source = (!$this->option("preview") && !$firmante->cert_required) ? $name_after : $output;
          $margin += $this->vars_sign['box_wh']["w"];
        }

        if (!$this->option("preview")) {
          if ($firmante->cert_required) { // Si el ultimo firmante es avanzado, delegar documentos de firma híbrida al FirmaAvanzadaController para continuación y/o cierre
            $firma_avanzada = new FirmaAvanzadaController;
            $firma_avanzada->Continuar($documento->firmantes->last());
          } else {
            $documento->documento_status_id = 3;
            $documento->hash_signed = hash("sha256", File::get($source));
            if (!empty($documento->fc_status_id) && $documento->fc_status_id == 1) {
              $documento->fc_date_requested = Carbon::now();
              $documento->fc_status_id = 2;
            }
            $documento->save();
            $documento->refresh(); // Para actualizar las relationships cargadas antes del update

            if ($session->getConstancia($documento) === true) {
              $documento->refresh(); // Se necesita ya que el objeto documento regresa sin IP y Filename despues de getConstancia.
              if ($session->signedCover($documento) === true) {
                if ($session->signedCoverFirmantes($documento) === true) {
                  if (File::exists($this->documento_path . $documento->id . "_unsigned") && File::exists($this->documento_path . $documento->id . "_signed")) {
                    exec("php " . base_path() . "/artisan concerteza:documento-merge-cover " . $documento->id, $salida, $result);

                    if ($result == 0) {
                      $inform = new NotificadoController;
                      $redirect = new Redirect;
                      $redirect->id = hash("crc32", uniqid('download-signed', true)); // (prefix [opcional. Evita colision de microsegundos], more_entropy [Genera 23 chars]) (79,228,162,514,264,337,593,543,950,336 valores posibles)
                      $redirect->uri = "download-signed/" . $documento->_id;
                      $redirect->uid = $documento->users->id;
                      $redirect->roles_id = $documento->users->group_id;
                      $sms_types_id = $redirect->sms_types_id = 3;
                      $redirect->save();
                      foreach ($documento->firmantes as $firmante) {
                        $documento->notificados->push($firmante); // Incluir a los firmantes (solo los que han sido previamente notificados) en la notificacion
                      }
                      if (User::find($documento->users->id)->can('documento.notifications.sign')) $documento->notificados->push($documento->users); // Incluir al propietario en la notificacion
                      $documento['redirects_id'] = $redirect->id;
                      $documento['actor'] = $documento->users->only('name', 'email');
                      $inform->sendNotificacion($documento, "documento_notificacion_firma_completa", Carbon::now());
                      File::delete($this->documento_path . $this->argument("id") . "_preview");
                      return true;
                    } else {
                      $documento->merge_pending = true;
                      $documento->save();
                    }
                  } else $this->info('ui.messages.modules.fail.not-found');
                } else $this->info('ui.documento.messages.fail.sign.no-cover-firmantes');
              } else $this->info('ui.documento.messages.fail.sign.no-cover');
            } else $this->info('ui.documento.messages.fail.sign.no-constancia');
          }
        } else return true;
      } else $this->info('ui.documento.messages.fail.sign.no-firmante');
    } else $this->info('ui.documento.messages.fail.sign.wrong-status');
  }
}
