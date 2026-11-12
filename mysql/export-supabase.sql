-- ============================================================
-- 从 Supabase 导出全部数据（在 Supabase Dashboard → SQL Editor 执行）
--
-- 执行后会返回一行 JSON（列名 dump）。把这一格完整复制出来，
-- 存成 supabase-dump.json，再在装了 MySQL 的服务器上运行：
--     php import.php supabase-dump.json
--
-- 说明：
-- · 能读到 auth.users.encrypted_password（bcrypt），迁移后原密码继续可用；
-- · 如果某个账号是第三方登录（没有密码），import.php 会提示需要重置密码；
-- · 本脚本只读，不改任何数据。
-- ============================================================

select jsonb_pretty(
  jsonb_build_object(
    'exported_at', now(),

    'users', coalesce((
      select jsonb_agg(
        jsonb_build_object(
          'id', u.id,
          'email', u.email,
          'password_hash', u.encrypted_password,
          'created_at', u.created_at
        ) order by u.created_at
      )
      from auth.users u
      where u.email is not null
    ), '[]'::jsonb),

    'profiles', coalesce((
      select jsonb_agg(
        jsonb_build_object(
          'id', p.id,
          'name', p.name,
          'emoji', p.emoji,
          'created_at', p.created_at
        )
      )
      from public.profiles p
    ), '[]'::jsonb),

    'records', coalesce((
      select jsonb_agg(
        jsonb_build_object(
          'uid', r.uid,
          'd', r.d,
          'payload', r.payload,
          'updated_at', r.updated_at
        )
      )
      from public.records r
    ), '[]'::jsonb),

    'posts', coalesce((
      select jsonb_agg(
        jsonb_build_object(
          'id', p.id,
          'uid', p.uid,
          'text', p.text,
          'workout', p.workout,
          'ts', p.ts
        ) order by p.ts
      )
      from public.posts p
    ), '[]'::jsonb),

    'likes', coalesce((
      select jsonb_agg(
        jsonb_build_object(
          'post_id', l.post_id,
          'uid', l.uid,
          'ts', l.ts
        )
      )
      from public.post_likes l
    ), '[]'::jsonb),

    'comments', coalesce((
      select jsonb_agg(
        jsonb_build_object(
          'id', c.id,
          'post_id', c.post_id,
          'uid', c.uid,
          'text', c.text,
          'ts', c.ts
        ) order by c.ts
      )
      from public.post_comments c
    ), '[]'::jsonb),

    'settings', coalesce((
      select jsonb_agg(
        jsonb_build_object(
          'uid', s.uid,
          'favs', s.favs,
          'custom', s.custom,
          'cats', s.cats,
          'nickname', s.nickname,
          'theme', s.theme,
          'palette', s.palette,
          'updated_at', s.updated_at
        )
      )
      from public.settings s
    ), '[]'::jsonb)
  )
) as dump;
