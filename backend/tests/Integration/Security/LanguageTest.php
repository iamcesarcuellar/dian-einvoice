<?php

namespace Tests\Integration\Security;

use App\Modules\Catalog\Models\Tax;
use App\Modules\Delivery\Support\AmountInWords;
use App\Modules\Identity\Models\Company;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\IntegrationTestCase;

/**
 * The API speaks the language of Accept-Language: English by default and for any language it does not have, Spanish
 * for es, es-CO or es-419. Validation messages with the names of their fields, the details of business errors and the
 * fixed texts of problems come in that language, and the response says which in Content-Language. A new company
 * takes the language of the request that creates it for its documents, and the emails a request sends (an
 * invitation, a password reset link) are in its language.
 */
final class LanguageTest extends IntegrationTestCase
{
    private const array SPANISH = ['Accept-Language' => 'es-CO,es;q=0.9,en;q=0.8'];

    public function test_a_validation_error_comes_in_spanish_when_asked_and_in_english_otherwise(): void
    {
        $body = ['name' => '', 'email' => 'not-an-email', 'password' => 'short', 'company' => ['name' => '']];

        $spanish = $this->postJson('/api/v1/auth/register', $body, self::SPANISH)
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Content-Language', 'es');

        $this->assertSame('Contenido no procesable', $spanish->json('title'));
        $this->assertSame('Los datos enviados no son válidos.', $spanish->json('detail'));
        $this->assertSame(['El campo nombre completo es obligatorio.'], $spanish->json('errors.name'));
        $this->assertSame(['El campo correo debe ser un correo válido.'], $spanish->json('errors.email'));
        $this->assertSame(['El campo nombre de la empresa es obligatorio.'], $this->errors($spanish)['company.name'] ?? null);
        $this->assertStringContainsString('Accept-Language', (string) $spanish->headers->get('Vary'));

        foreach ([[], ['Accept-Language' => 'fr-FR,fr;q=0.9']] as $headers) {
            $english = $this->postJson('/api/v1/auth/register', $body, $headers)->assertStatus(422)->assertHeader('Content-Language', 'en');

            $this->assertSame('Unprocessable Content', $english->json('title'));
            $this->assertSame('The given data was invalid.', $english->json('detail'));
            $this->assertSame(['The name field is required.'], $english->json('errors.name'));
            $this->assertSame(['The email field must be a valid email address.'], $english->json('errors.email'));
            $this->assertSame(['The company name field is required.'], $this->errors($english)['company.name'] ?? null);
        }
    }

    public function test_a_business_error_comes_in_spanish_when_asked_and_in_english_otherwise(): void
    {
        $company = $this->company();
        $draft = $this->draftInvoice($company, []);
        $issue = "/api/v1/invoices/{$draft->id}/issue";

        // Nothing to issue: a draft without lines.
        $spanish = $this->signedIn($company->user, $company)->postJson($issue, [], self::SPANISH)->assertStatus(422)->assertHeader('Content-Language', 'es');
        $this->assertSame(['Agrega al menos una línea.'], $spanish->json('errors.lines'));

        $english = $this->signedIn($company->user, $company)->postJson($issue)->assertStatus(422)->assertHeader('Content-Language', 'en');
        $this->assertSame(['Add at least one line.'], $english->json('errors.lines'));

        // A conflict and a record that does not exist, with their titles.
        $issued = $this->issue($company, $this->draftInvoice($company));

        $conflict = $this->signedIn($company->user, $company)->deleteJson("/api/v1/invoices/{$issued->id}", [], self::SPANISH)->assertStatus(409);
        $this->assertSame('Conflicto', $conflict->json('title'));
        $this->assertSame('Las facturas emitidas no se pueden eliminar.', $conflict->json('detail'));

        $conflict = $this->signedIn($company->user, $company)->deleteJson("/api/v1/invoices/{$issued->id}")->assertStatus(409);
        $this->assertSame('Conflict', $conflict->json('title'));
        $this->assertSame('Issued invoices cannot be deleted.', $conflict->json('detail'));

        $missing = $this->getJson('/api/v1/nothing-lives-here', self::SPANISH)->assertStatus(404);
        $this->assertSame(['No encontrado', 'El recurso que pediste no existe.'], [$missing->json('title'), $missing->json('detail')]);
    }

    public function test_a_new_company_takes_the_language_of_the_request_for_its_documents(): void
    {
        $company = $this->company();
        $body = ['name' => 'Synthetic Spanish Company SAS', 'identification_number' => '990004201'];

        $created = $this->signedIn($company->user, $company)->postJson('/api/v1/companies', $body, self::SPANISH)->assertCreated();
        $spanish = Company::query()->findOrFail($this->text($created->json('data.id')));
        $this->assertSame('es', $created->json('data.document_language'));
        // Its default taxes and withholdings are named in Spanish too.
        $this->assertTrue(Tax::query()->withoutGlobalScopes()->where('company_id', $spanish->id)->where('name', 'IVA 0 % (exento)')->exists());

        $created = $this->signedIn($company->user, $company)->postJson('/api/v1/companies', [...$body, 'identification_number' => '990004202'])->assertCreated();
        $this->assertSame('en', $created->json('data.document_language'));

        // It changes in the company's settings, to en or es only.
        $this->signedIn($company->user, $company)->patchJson("/api/v1/companies/{$spanish->id}", ['document_language' => 'en'])->assertOk()->assertJsonPath('data.document_language', 'en');
        $this->signedIn($company->user, $company)->patchJson("/api/v1/companies/{$spanish->id}", ['document_language' => 'fr'], self::SPANISH)
            ->assertStatus(422)
            ->assertJsonPath('errors.document_language', ['El valor elegido en idioma de los documentos no es válido.']);

        foreach ([$spanish->id, $this->text($created->json('data.id'))] as $id) {
            Company::query()->whereKey($id)->delete();
        }
    }

    public function test_an_invitation_email_comes_in_the_language_of_the_request_that_sends_it(): void
    {
        $company = $this->company();

        $this->signedIn($company->user, $company)
            ->postJson('/api/v1/team/invitations', ['email' => 'synthetic-invitado@example.com', 'role' => 'member'], self::SPANISH)
            ->assertCreated();
        $email = $this->lastEmail();
        $this->assertStringContainsString('te invitó a', (string) $email->getSubject());
        $this->assertStringContainsString('Aceptar la invitación', (string) $email->getTextBody());

        $this->signedIn($company->user, $company)
            ->postJson('/api/v1/team/invitations', ['email' => 'synthetic-invited@example.com', 'role' => 'member'])
            ->assertCreated();
        $this->assertStringContainsString('invited you to', (string) $this->lastEmail()->getSubject());

        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $company->user->email], self::SPANISH)->assertStatus(202)
            ->assertJsonPath('data.message', 'Si alguna cuenta usa este correo, ya va en camino un enlace para elegir una nueva contraseña. Funciona una vez, durante 60 minutos.');
        $this->assertStringContainsString('Restablece tu contraseña', (string) $this->lastEmail()->getSubject());
    }

    public function test_amounts_in_words_in_both_languages(): void
    {
        $this->assertSame('One million two hundred thousand Colombian pesos', AmountInWords::pesos('1200000', 'en'));
        $this->assertSame('Un millón doscientos mil pesos colombianos', AmountInWords::pesos('1200000', 'es'));
        $this->assertSame('Un millón de pesos colombianos', AmountInWords::pesos('1000000', 'es'));
        $this->assertSame('Quinientos sesenta y un mil ochenta y cinco pesos colombianos', AmountInWords::pesos('561085', 'es'));
        $this->assertSame('Veintiún pesos colombianos con 50/100', AmountInWords::pesos('21.50', 'es'));
        $this->assertSame('Siete millones setecientos mil pesos colombianos', AmountInWords::pesos('7700000', 'es'));
    }

    /**
     * The validation messages of a problem, by field.
     *
     * @param  TestResponse<Response>  $response
     * @return array<mixed>
     */
    private function errors(TestResponse $response): array
    {
        $errors = $response->json('errors');
        $this->assertIsArray($errors);

        return $errors;
    }

    /** The last email the mailer sent. */
    private function lastEmail(): Email
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $message = $transport->messages()->last();
        $this->assertInstanceOf(SentMessage::class, $message);
        $email = $message->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }
}
