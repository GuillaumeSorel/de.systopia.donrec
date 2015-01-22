<?php
/*-------------------------------------------------------+
| SYSTOPIA Donation Receipts Extension                   |
| Copyright (C) 2013-2014 SYSTOPIA                       |
| Author: B. Endres (endres -at- systopia.de)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| TODO: License                                          |
+--------------------------------------------------------*/

/**
 * This exporter creates CSV files
 */
class CRM_Donrec_Exporters_CSV extends CRM_Donrec_Logic_Exporter {

  /**
   * @return the display name
   */
  static function name() {
    return ts("CSV File");
  }

  /**
   * @return a html snippet that defines the options as form elements
   */
  static function htmlOptions() {
    return '';
  }

  /**
   * @return the ID of this importer class
   */
  public function getID() {
    return 'CSV';
  }


  /**
   * export this chunk of individual items
   *
   * @return array:
   *          'is_error': set if there is a fatal error
   *          'log': array with keys: 'type', 'timestamp', 'message'
   */
  public function exportSingle($chunk, $snapshotId, $is_test) {
    return $this->exportLine($chunk, $snapshotId, $is_test, false);
  }

  /**
   * bulk-export this chunk of items
   *
   * @return array:
   *          'is_error': set if there is a fatal error
   *          'log': array with keys: 'type', 'level', 'timestamp', 'message'
   */
  public function exportBulk($chunk, $snapshotId, $is_test) {
    return $this->exportLine($chunk, $snapshotId, $is_test, true);
  }

  /**
   * generate the final result
   *
   * @return array:
   *          'is_error': set if there is a fatal error
   *          'log': array with keys: 'type', 'level', 'timestamp', 'message'
   *          'download_url: URL to download the result
   *          'download_name: suggested file name for the download
   */
  public function wrapUp($snapshot_id, $is_test, $is_bulk) {
    $snapshot = CRM_Donrec_Logic_Snapshot::get($snapshot_id);
    $reply = array();

    // open file
    $preferredFileName = ts('donation_receipts');
    $preferredFileSuffix = ts('.csv');
    $temp_file = CRM_Donrec_Logic_File::makeFileName($preferredFileName, $preferredFileSuffix);
    $handle = fopen($temp_file, 'w');

    // get headers
    $headers = CRM_Donrec_Logic_ReceiptTokens::getFullTokenList();
    $headers = $this->flattenTokenData($headers);
    $headers = array_keys($headers);
    $header_written = false;

    // write them all into the file
    $ids = $snapshot->getIds();
    foreach($ids as $id) {
      $proc_info = $snapshot->getProcessInformation($id);
      $csv_data = $proc_info['CSV']['csv_data'];
      if (!empty($csv_data)) {
        if (!$header_written) {
          // extend header by extra fields
          $headers = array_merge($headers, array_keys($csv_data));
          $headers = array_unique($headers);

          // write header
          fputcsv($handle, $headers, ';', '"');
          $header_written = true;
        }

        // create and write a line
        $line = array();
        foreach ($headers as $field) {
          if (isset($csv_data[$field])) {
            $line[$field] = $csv_data[$field];
          } else {
            $line[$field] = '';
          }
        }
        fputcsv($handle, $line, ';', '"');
      }
    }

    // get process info iterator
    fclose($handle);

    // create the file
    $file = CRM_Donrec_Logic_File::createTemporaryFile($temp_file, $preferredFileName.$preferredFileSuffix);
    error_log("de.systopia.donrec: resulting CSV file URL is '$file'.");
    if (!empty($file)) {
      $reply['download_name'] = $preferredFileName;
      $reply['download_url'] = $file;
    }

    CRM_Donrec_Logic_Exporter::addLogEntry($reply, 'CSV process ended.', CRM_Donrec_Logic_Exporter::LOG_TYPE_INFO);
    return $reply;
  }

  /**
   * check whether all requirements are met to run this exporter
   *
   * @return array:
   *         'is_error': set if there is a fatal error
   *         'message': error message
   */
  public function checkRequirements() {
    return array('is_error' => FALSE);
  }

  /**
   * wil create bulk and/or individual items as CSV lines that
   * are stored in the process information field
   */
  private function exportLine($chunk, $snapshotId, $is_test, $is_bulk) {
    $reply = array();

    // get data from snapshot (#1399)
    $snapshot = CRM_Donrec_Logic_Snapshot::get($snapshotId);
    $snapshotReceipts = $snapshot->getSnapshotReceipts($chunk, $is_bulk, $is_test);

    foreach ($snapshotReceipts as $snapshotReceipt) {
      $values = $snapshotReceipt->getAllTokens();
      $flattened_data = $this->flattenTokenData($values);

      // add accumulated data
      $flattened_data['individual_count']                 = 0;
      $flattened_data['individual_receive_date']          = '';
      $flattened_data['individual_total_amount']          = '';
      $flattened_data['individual_non_deductible_amount'] = '';
      $flattened_data['individual_financial_type_id']     = '';
      $flattened_data['individual_financial_type']        = '';
      foreach ($values['lines'] as $line_id => $line) {
        $flattened_data['individual_count'] += 1;
        if ($flattened_data['individual_count'] > 1) {
          $flattened_data['individual_receive_date']          .= "\n";
          $flattened_data['individual_total_amount']          .= "\n";
          $flattened_data['individual_non_deductible_amount'] .= "\n";
          $flattened_data['individual_financial_type_id']     .= "\n";
          $flattened_data['individual_financial_type']        .= "\n";
        }
        $flattened_data['individual_receive_date']          .= $line['receive_date'];
        $flattened_data['individual_total_amount']          .= $line['total_amount'];
        $flattened_data['individual_non_deductible_amount'] .= $line['non_deductible_amount'];
        $flattened_data['individual_financial_type_id']     .= $line['financial_type_id'];
        $flattened_data['individual_financial_type']        .= $line['financial_type'];
      }

      // store the data in the process information
      $this->updateProcessInformation($snapshotReceipt->getID(), array('csv_data' => $flattened_data));
    }

    // add a log entry
    CRM_Donrec_Logic_Exporter::addLogEntry($reply, 'CSV processed ' . count($chunk) . ' items.', CRM_Donrec_Logic_Exporter::LOG_TYPE_INFO);
    return $reply;
  }

  private function flattenTokenData($values) {
    $flattened_data = array();
    foreach ($values as $key => $value) {
      if (is_array($value)) {
        if ($key=='lines' || $key=='items') {
          // don't do anything here
        } else {
          foreach ($value as $key2 => $value2) {
            $flattened_data[$key.'_'.$key2] = $value2;
          }
        }
      } else {
        $flattened_data[$key] = $value;
      }
    }
    return $flattened_data;  
  }
}
