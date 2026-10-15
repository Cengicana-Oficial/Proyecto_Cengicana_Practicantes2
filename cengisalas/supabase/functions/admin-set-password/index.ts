import { withSupabase } from 'npm:@supabase/server@^1'

type PasswordRequest = {
  userId?: string
  password?: string
}

type UserClaims = {
  id?: string
  sub?: string
}

const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export default {
  fetch: withSupabase({ auth: 'user' }, async (request, context) => {
    if (request.method !== 'POST') {
      return Response.json({ error: 'Método no permitido.' }, { status: 405 })
    }

    let body: PasswordRequest
    try {
      body = await request.json()
    } catch {
      return Response.json({ error: 'La solicitud no contiene datos válidos.' }, { status: 400 })
    }

    const userId = body.userId?.trim() ?? ''
    const password = body.password ?? ''
    if (!uuidPattern.test(userId)) {
      return Response.json({ error: 'El usuario indicado no es válido.' }, { status: 400 })
    }
    if (password.length < 8 || password.length > 72) {
      return Response.json({ error: 'La contraseña debe contener entre 8 y 72 caracteres.' }, { status: 400 })
    }

    const claims = context.userClaims as UserClaims | null
    const administratorId = claims?.id ?? claims?.sub
    if (!administratorId) {
      return Response.json({ error: 'No fue posible identificar la sesión administrativa.' }, { status: 401 })
    }

    const { data: administrator, error: administratorError } = await context.supabaseAdmin
      .from('profiles')
      .select('role,is_active,account_status')
      .eq('id', administratorId)
      .single()

    if (administratorError || administrator?.role !== 'admin' || !administrator.is_active || administrator.account_status !== 'active') {
      return Response.json({ error: 'No tienes permisos para cambiar contraseñas.' }, { status: 403 })
    }

    const { data: target, error: targetError } = await context.supabaseAdmin
      .from('profiles')
      .select('id,full_name,account_status')
      .eq('id', userId)
      .single()

    if (targetError || !target) {
      return Response.json({ error: 'El usuario no existe.' }, { status: 404 })
    }
    if (target.account_status !== 'active') {
      return Response.json({ error: 'Solo se puede cambiar la contraseña de una cuenta activa.' }, { status: 409 })
    }

    const { error: passwordError } = await context.supabaseAdmin.auth.admin.updateUserById(userId, { password })
    if (passwordError) {
      return Response.json({ error: passwordError.message }, { status: 400 })
    }

    const { error: auditError } = await context.supabaseAdmin.from('audit_log').insert({
      actor_id: administratorId,
      actor_role: 'admin',
      action: 'change_password',
      entity_type: 'user',
      entity_id: userId,
      new_data: { changed_by_administrator: true },
    })
    if (auditError) console.error('No se pudo registrar la auditoría del cambio de contraseña:', auditError)

    return Response.json({ success: true, userId, userName: target.full_name })
  }),
}
