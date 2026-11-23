<?php

// The email that invites someone to join a company, in Spanish.

return [

    'subject' => ':inviter te invitó a :company en :app',
    'subject_anonymous' => 'Te invitaron a :company en :app',
    'preheader' => 'Únete a :company en :app como :role.',
    'greeting' => 'Hola:',
    'intro' => ':inviter te invitó a unirte a :company en :app, su plataforma de facturación electrónica.',
    'intro_anonymous' => 'Te invitaron a unirte a :company en :app, su plataforma de facturación electrónica.',
    'company' => 'Empresa',
    'role' => 'Rol',
    'email' => 'Correo invitado',
    'expires' => 'Enlace válido hasta',
    'expires_value' => ':date (hora de Colombia)',
    'button' => 'Aceptar la invitación',
    'link' => 'Si el botón no funciona, copia este enlace en tu navegador:',
    'account' => 'Si aún no tienes una cuenta, eliges tu nombre y tu contraseña al aceptar.',
    'ignore' => 'Si no esperabas esta invitación, ignora este correo: no pasa nada mientras nadie use el enlace.',
    'roles' => [
        'owner' => 'Propietario',
        'admin' => 'Administrador',
        'member' => 'Miembro',
        'viewer' => 'Lector',
    ],
    'role_descriptions' => [
        'owner' => 'Los propietarios manejan todo en la empresa, incluidos su equipo y sus propietarios.',
        'admin' => 'Los administradores manejan todo en la empresa, incluido su equipo, menos a sus propietarios.',
        'member' => 'Los miembros manejan contactos, ítems y documentos, y registran pagos.',
        'viewer' => 'Los lectores ven todo y no cambian nada.',
    ],
    'test_title' => 'Ambiente de pruebas',
    'test_notice' => 'Esta plataforma corre localmente con datos sintéticos. Un buzón local de desarrollo atrapó este correo, que nunca salió del computador que lo envió.',
    'signature' => 'Enviado por :app.',

];
