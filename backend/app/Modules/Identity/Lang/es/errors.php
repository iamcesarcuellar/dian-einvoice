<?php

// The details of the error responses of accounts, companies, teams and invitations, in Spanish.

return [

    'not_registered' => 'No pudimos crear una cuenta con estos datos. Si ya tienes una cuenta, inicia sesión o restablece tu contraseña; si tu empresa ya usa esta plataforma, pídele a uno de sus propietarios que te invite.',
    'session_only' => 'El inicio de sesión con sesión solo está disponible para la aplicación web.',
    'too_many_sign_ins' => 'Demasiados intentos de inicio de sesión. Vuelve a intentarlo en :seconds segundos.',
    'link_sent' => 'Si alguna cuenta usa este correo, ya va en camino un enlace para elegir una nueva contraseña. Funciona una vez, durante 60 minutos.',
    'invalid_link' => 'Este enlace para restablecer la contraseña ya no es válido: ya se usó, venció o es de otro correo. Pide uno nuevo.',
    'password_changed' => 'Tu contraseña cambió. Inicia sesión con ella.',

    'other_company_key' => 'Esta llave de API es de otra empresa. No envíes X-Company-Id, o envía la empresa de la llave.',
    'read_only_key' => 'La llave de API :key es de solo lectura: no puede escribir.',
    'missing_company' => 'Envía la empresa actual en la cabecera X-Company-Id.',
    'not_member' => 'No eres miembro de esta empresa.',
    'role_forbids' => 'Tu rol en esta empresa no permite esta acción.',
    'key_settings' => 'Las llaves de API no pueden cambiar la configuración: un propietario o un administrador la cambia en la aplicación web.',
    'key_forbids' => 'Esta llave de API no permite esta acción.',
    'edit_company' => 'Solo los propietarios y los administradores pueden editar esta empresa.',

    'team' => [
        'last_owner_role' => 'La empresa necesita al menos un propietario. Primero haz propietario a otro miembro.',
        'remove_self' => 'No puedes quitarte a ti mismo de la empresa. Otro propietario o administrador puede hacerlo.',
        'remove_last_owner' => 'La empresa necesita al menos un propietario, así que no se puede quitar al último.',
        'grant_owner' => 'Solo un propietario puede hacer propietario a alguien.',
        'manage_owner' => 'Solo un propietario puede cambiar o quitar a un propietario.',
    ],

    'invitations' => [
        'member' => ':email ya es miembro de esta empresa.',
        'pending' => ':email ya tiene una invitación pendiente a esta empresa; mejor reenvíala.',
        'resend_accepted' => 'Esta invitación ya se aceptó.',
        'resend_canceled' => 'Esta invitación se canceló; envía una nueva.',
        'resend_owner' => 'Solo un propietario puede reenviar una invitación para ser propietario.',
        'resend_member' => ':email ya es miembro de esta empresa; mejor cancela esta invitación.',
        'cancel_accepted' => 'Esta invitación ya se aceptó; si quieres, quita al miembro.',
        'expired' => 'Esta invitación venció. Pídele a la empresa que la envíe de nuevo.',
        'canceled' => 'Esta invitación se canceló.',
        'accepted' => 'Esta invitación ya se aceptó.',
        'account_exists' => 'Ya existe una cuenta para :email. Inicia sesión para aceptar la invitación.',
        'web_app_only' => 'Las invitaciones se aceptan en la aplicación web.',
        'sign_in_as' => 'Inicia sesión como :email para aceptar esta invitación.',
        'other_user' => 'Esta invitación es para :email. Cierra sesión y luego inicia sesión como :email para aceptarla.',
    ],

];
