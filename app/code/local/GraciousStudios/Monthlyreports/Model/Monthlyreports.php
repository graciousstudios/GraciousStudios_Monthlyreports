<?php

class GraciousStudios_Monthlyreports_Model_Monthlyreports extends Mage_Core_Model_Abstract {

    const TYPE_NAME_INVOICES = 'invoices';
    const TYPE_NAME_REFUNDS = 'refunds';

    protected $startDate;
    protected $endDate;
    protected $files;

    /**
     * Generate reports and send email
     */
    public function generate()  {
        $startTimestamp = mktime(0, 0, 0, date('m')-1, 1, date('Y'));
        $endTimestamp = mktime(0, 0, 0, date('m'), 1, date('Y'));

        $this->startDate = date('Y-m-d H:i:s', $startTimestamp);
        $this->endDate = date('Y-m-d H:i:s', $endTimestamp);

        $this->invoices();
        $this->refunds();
        $this->sendEmail();
    }

    /**
     * Calculate invoice totals
     */
    protected function invoices()   {

        $sql = "
            SELECT
                DATE_FORMAT(created_at, '%d-%m-%Y') AS 'Date',
                COUNT(DISTINCT entity_id) AS 'Orders',
                ROUND(SUM(total_qty),2) AS 'Amount of items',
                ROUND(SUM(grand_total),2) AS 'Total (inc. tax)',
                ROUND(SUM(tax_amount),2) AS 'Tax (total)',
                ROUND(SUM(shipping_incl_tax),2) AS 'Shipping (inc. tax)'
            FROM 
                sales_flat_invoice
            WHERE 
                (created_at >= '" . $this->startDate . "') AND 
                (created_at < '" . $this->endDate . "')
            GROUP BY 
                DATE_FORMAT(created_at, '%d-%m-%Y')
           ORDER BY 
                Date ASC
        ";
        $resource = Mage::getSingleton('core/resource');
        $readConnection = $resource->getConnection('core_read');
        $results = $readConnection->fetchAll($sql);
        $this->writeCSV(self::TYPE_NAME_INVOICES, $results);
    }

    /**
     * Calculate refund totals
     */
    protected function refunds()    {
        $sql = "
            SELECT 
                DATE_FORMAT(sales_flat_creditmemo.created_at, '%d-%m-%Y') AS 'Date', 
                COUNT(DISTINCT sales_flat_creditmemo.entity_id) AS 'Orders', 
                ROUND(SUM(sales_flat_creditmemo.grand_total),2) AS 'Total (inc. tax)', 
                ROUND(SUM(sales_flat_creditmemo.tax_amount),2) AS 'Tax (total)', 
                ROUND(SUM(sales_flat_creditmemo_item.qty),2) AS 'Amount of items'
            FROM 
                sales_flat_creditmemo
                LEFT JOIN sales_flat_creditmemo_item ON 
                    sales_flat_creditmemo.entity_id = sales_flat_creditmemo_item.parent_id
            WHERE 
                (sales_flat_creditmemo.created_at >= '" . $this->startDate . "') AND 
                (sales_flat_creditmemo.created_at < '" . $this->endDate . "')
            GROUP BY 
                DATE_FORMAT(created_at, '%d-%m-%Y')
            ORDER BY 
                Date ASC
        ";
        $resource = Mage::getSingleton('core/resource');
        $readConnection = $resource->getConnection('core_read');
        $results = $readConnection->fetchAll($sql);
        $this->writeCSV(self::TYPE_NAME_REFUNDS, $results);
    }

    /**
     * Write an array to a CSV file
     *
     * @param $type
     * @param $data
     */
    public function writeCSV($type, $data) {

        if(is_array($data) && !empty($data) && isset($data[0])) {
            // Create directory vars
            $varDir = Mage::getBaseDir() . DS . 'var';
            $fileName = 'monthlyreports_' . $type . '_' . date('Y-m') . '_' . date('His') . '.csv';
            $exportDir = $varDir . DS . 'monthlyreports';
            $exportFilename = $exportDir . DS . $fileName;

            // Prepare export file & directory
            $io = new Varien_Io_File();
            $io->setAllowCreateFolders(true);
            $io->open(['path' => $exportDir]);
            $io->streamOpen($exportFilename, 'w+');
            $io->streamLock(true);

            // Prepare headers
            $_headers = array_keys($data[0]);
            // Write headers to file
            $io->streamWriteCsv($_headers, ';');

            // Loop over each line and write it
            $itemCount=0;
            foreach($data as $_line) {
                // Write to line
                $io->streamWriteCsv($_line, ';', chr(0));
                $itemCount++;
            }
            // Unlock and close the stream
            $io->streamUnlock();
            $io->streamClose();

            $this->files[] = [
                $fileName => $exportFilename
            ];

            // Send it in an email
//            $this->mailCsv($exportFilename, $fileName, $itemCount);
        }else{
            Mage::log('No ' . $type . ' found between ' . $this->startDate . ' and ' . $this->endDate, null, 'monthlyreports.log');
        }
    }


    /**
     * Send the CSV file to the email adresses filled in in the backend
     *
     * @param null $file
     * @param string $filename
     * @param int $items
     */
    protected function sendEmail() {
        $mail = new Zend_Mail('utf-8');
        $emails = Mage::getStoreConfig('monthlyreports/monthlyreports/email', Mage::app()->getStore());
        $recipients = explode(PHP_EOL, $emails);
        if(!empty($recipients)) {
            $subject = 'Monthly Reports Export : ' . gethostname() . ' : ' . date('YmdHis');
            $mailBody = '<strong>' . $subject . '</strong><br/><br/>';
            $mail->setBodyHtml($mailBody)
                ->addTo($recipients)
                ->setSubject($subject)
                ->setFrom(Mage::getStoreConfig('trans_email/ident_general/email'), 'Koopjedeal.nl')
            ;

            // Attach file if we have one
            foreach($this->files as $aFile)  {
                foreach($aFile as $fileName=>$exportFilename)   {
                    if(!is_null($exportFilename)) {
                        Mage::log('Found file, attaching = ' . $exportFilename, null, 'monthlyreports.log');
                        $attachment = file_get_contents($exportFilename);
                        $mail->createAttachment($attachment, Zend_Mime::TYPE_OCTETSTREAM, Zend_Mime::DISPOSITION_ATTACHMENT, Zend_Mime::ENCODING_BASE64, $fileName);
                    }
                }
            }

            try {
                $mail->send();
            } catch(Exception $e) {
                Mage::log('Excption = ' . $e->getMessage(), null, 'monthlyreports.log');
                Mage::logException($e);
            }
        }else{
            Mage::log('No email addresses filled in, not sending!', null, 'monthlyreports.log');
        }

    }

}
