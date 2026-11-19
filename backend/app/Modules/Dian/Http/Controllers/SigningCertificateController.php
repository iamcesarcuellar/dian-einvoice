<?php

namespace App\Modules\Dian\Http\Controllers;

use App\Modules\Dian\Http\Requests\SigningCertificateRequest;
use App\Modules\Dian\Http\Resources\SigningCertificateResource;
use App\Modules\Dian\Services\SigningCertificates;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The signing certificate of the current company. Owners and admins only. Only metadata comes
 * back: subject, issuer, serial number, validity and status.
 */
final class SigningCertificateController
{
    /**
     * The certificate, or null when the company has none.
     */
    public function show(SigningCertificates $certificates): JsonResponse
    {
        $certificate = $certificates->current();

        return response()->json(['data' => $certificate === null ? null : SigningCertificateResource::make($certificate)]);
    }

    /**
     * Upload a .p12 or .pfx file with its password; it replaces the current certificate. A file
     * that does not open, or lacks an RSA private key with its certificate, answers 422.
     */
    public function store(SigningCertificateRequest $request, SigningCertificates $certificates): JsonResponse
    {
        $certificate = $certificates->upload(
            (string) $request->file('file')?->get(),
            $request->safe()->string('password')->toString(),
        );

        return SigningCertificateResource::make($certificate)->response()->setStatusCode(201);
    }

    /**
     * Generate a synthetic self-signed test certificate (RSA 2048, SHA-256, two years) with the
     * NIT and legal name of the company; it replaces the current certificate. It is not issued by
     * an accredited certification authority, so the DIAN would not accept its signatures.
     */
    public function generateTest(SigningCertificates $certificates, CurrentCompany $currentCompany): JsonResponse
    {
        return SigningCertificateResource::make($certificates->generateTest($currentCompany->company()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Remove the certificate and its key. Invoices cannot be issued until another one is added.
     */
    public function destroy(SigningCertificates $certificates): Response
    {
        $certificates->remove();

        return response()->noContent();
    }
}
