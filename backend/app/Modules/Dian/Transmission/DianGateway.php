<?php

namespace App\Modules\Dian\Transmission;

/**
 * The DIAN web service for individual documents (annex 1.9, section 7): SendBillSync receives a
 * zip with one signed UBL document and answers with its validation; GetStatus answers the state
 * of a document by its track ID, its CUFE. In this project the other side is always the local
 * simulator. Implementations never throw for transport failures: every call returns how it ended.
 */
interface DianGateway
{
    public const string SEND_BILL_SYNC = 'SendBillSync';

    public const string GET_STATUS = 'GetStatus';

    /** Who answers, stored with every call and shown with every result: "simulator". */
    public function name(): string;

    /** The URL the calls go to. */
    public function endpoint(): string;

    public function sendBillSync(string $zipFileName, string $zip): GatewayCall;

    public function getStatus(string $trackId): GatewayCall;
}
