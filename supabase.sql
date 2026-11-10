-- ============================================================
-- PUSHUP Lite v1.0 · Supabase 建表脚本（精简版）
-- 用法：Supabase Dashboard → SQL Editor → New query → 整段粘贴 → Run
-- 只需执行一次；重复执行也安全（表 / 策略均为幂等写法）
-- 前置：Authentication → Providers → Email
--        ① 关闭 "Confirm email"  ② Minimum password length 调到 1
-- ============================================================
--
-- 与完整版的区别（Lite 只保留 6 张表）：
--   ✗ 删除 follows   —— 没有好友 / 关注功能
--   ✗ 删除 messages  —— 没有聊天功能
--   ✗ profiles.avatar —— 头像改成 emoji 字符串（单字符，几十字节）
--   ✗ posts.images   —— 动态不再发图
--   ✗ posts.vis      —— 没有好友关系，动态统一全网可见
--
-- 账号说明：账号 = 邮箱 + 密码，由 Supabase Auth 管理，不发验证码。
-- 该脚本不会在任何表里存邮箱或手机号：profiles 只有昵称与头像 emoji，
-- 所以即使 anon / publishable key 公开，也拿不到别人的账号信息。
-- ============================================================

-- ---------- 资料 ----------
create table if not exists public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  name text not null default '新朋友',
  emoji text not null default '💪',       -- Lite：头像就是一个 emoji，不再存 base64 图片
  created_at timestamptz not null default now()
);

-- 老库升级：把原来的 avatar（base64 大字段）去掉，避免它继续被「所有人可读」暴露，
-- 也避免客户端误拉几十 KB 的图片数据。
alter table public.profiles add column if not exists emoji text not null default '💪';
alter table public.profiles drop column if exists avatar;
alter table public.profiles drop column if exists phone;

-- ---------- 动态 ----------
create table if not exists public.posts (
  id text primary key,
  uid uuid not null references public.profiles(id) on delete cascade,
  text text not null default '',
  workout jsonb,                                -- [{n,v,u}] 今日成果快照
  ts bigint not null
);
create index if not exists posts_ts_idx on public.posts (ts desc);

-- 老库升级：删掉图片列和可见性列
alter table public.posts drop column if exists images;
alter table public.posts drop column if exists vis;

-- ---------- 动态互动：点赞 / 评论 ----------
-- 点赞：一人一条，重复点赞靠主键天然去重
create table if not exists public.post_likes (
  post_id text not null references public.posts(id) on delete cascade,
  uid uuid not null references public.profiles(id) on delete cascade,
  ts timestamptz not null default now(),
  primary key (post_id, uid)
);

-- 评论：文字（可含表情字符），按动态聚合
create table if not exists public.post_comments (
  id text primary key,
  post_id text not null references public.posts(id) on delete cascade,
  uid uuid not null references public.profiles(id) on delete cascade,
  text text not null default '',
  ts bigint not null
);
create index if not exists post_comments_idx on public.post_comments (post_id, ts);

-- ---------- 训练数据同步：records / settings ----------
-- records：一天一条，改一次就 upsert 一次；payload 是整条训练记录
create table if not exists public.records (
  uid uuid not null references auth.users(id) on delete cascade,
  d text not null,                                     -- YYYY-MM-DD
  payload jsonb not null default '{}'::jsonb,          -- {d,p,w,a,note,items:[{id,n,v,u}]}
  updated_at timestamptz not null default now(),
  primary key (uid, d)
);
create index if not exists records_uid_idx on public.records (uid);

-- settings：常用动作 / 自定义动作 / 昵称 / 主题 / 配色，按字段 PATCH
create table if not exists public.settings (
  uid uuid primary key references auth.users(id) on delete cascade,
  favs jsonb,
  custom jsonb,
  cats jsonb,
  nickname text,
  theme text,
  palette text,
  updated_at timestamptz not null default now()
);
alter table public.settings drop column if exists avatar;

-- ---------- 清掉完整版遗留的表（如果这个项目之前跑过完整版脚本）----------
-- 数据会一起删掉，请确认不再需要聊天 / 关注数据再执行这一段。
drop table if exists public.messages;
drop table if exists public.follows;

-- ---------- 注册后自动建资料 ----------
create or replace function public.handle_new_user()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
  insert into public.profiles (id, name)
  values (
    new.id,
    coalesce(new.raw_user_meta_data->>'name', '新朋友')
  )
  on conflict (id) do nothing;
  return new;
end;
$$;

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_user();

-- ---------- 行级权限 RLS ----------
alter table public.profiles      enable row level security;
alter table public.posts         enable row level security;
alter table public.records       enable row level security;
alter table public.settings      enable row level security;
alter table public.post_likes    enable row level security;
alter table public.post_comments enable row level security;

-- profiles：所有人可读（动态要显示昵称头像），只能改自己
drop policy if exists "profiles 所有人可读" on public.profiles;
create policy "profiles 所有人可读" on public.profiles for select using (true);
drop policy if exists "profiles 只能改自己" on public.profiles;
create policy "profiles 只能改自己" on public.profiles for update
  using (id = auth.uid()) with check (id = auth.uid());
drop policy if exists "profiles 可补建自己" on public.profiles;
create policy "profiles 可补建自己" on public.profiles for insert
  with check (id = auth.uid());

-- 昵称唯一（不区分大小写）：前端注册 / 改名都会先查一次，这里做最后一道兜底。
-- 如果库里已存在重名（早期版本留下的），建索引会失败，这里静默跳过，不影响其它语句。
do $$
begin
  create unique index if not exists profiles_name_uniq on public.profiles (lower(name));
exception when others then
  raise notice '昵称唯一索引未建立：库里已有重名资料，清理后可重建';
end $$;

-- posts：Lite 没有好友关系，动态统一全网可见；只能发 / 删自己的
drop policy if exists "posts 按可见性读取" on public.posts;
drop policy if exists "posts 所有人可读" on public.posts;
create policy "posts 所有人可读" on public.posts for select using (true);
drop policy if exists "posts 只能发自己的" on public.posts;
create policy "posts 只能发自己的" on public.posts for insert
  with check (uid = auth.uid());
drop policy if exists "posts 只能删自己的" on public.posts;
create policy "posts 只能删自己的" on public.posts for delete
  using (uid = auth.uid());

-- post_likes / post_comments：登录后都能读（动态下面要显示谁点了赞、说了什么），
-- 只能点赞 / 评论自己的身份，也只能取消自己的赞、删自己的评论
drop policy if exists "post_likes 登录后可读" on public.post_likes;
create policy "post_likes 登录后可读" on public.post_likes for select to authenticated using (true);
drop policy if exists "post_likes 只能点自己" on public.post_likes;
create policy "post_likes 只能点自己" on public.post_likes for insert to authenticated
  with check (uid = auth.uid());
drop policy if exists "post_likes 只能取消自己的" on public.post_likes;
create policy "post_likes 只能取消自己的" on public.post_likes for delete to authenticated
  using (uid = auth.uid());

drop policy if exists "post_comments 登录后可读" on public.post_comments;
create policy "post_comments 登录后可读" on public.post_comments for select to authenticated using (true);
drop policy if exists "post_comments 只能以自己身份发" on public.post_comments;
create policy "post_comments 只能以自己身份发" on public.post_comments for insert to authenticated
  with check (uid = auth.uid());
drop policy if exists "post_comments 只能删自己的" on public.post_comments;
create policy "post_comments 只能删自己的" on public.post_comments for delete to authenticated
  using (uid = auth.uid());

-- records：所有人都能看（登录后），但只有本人能增删改
drop policy if exists "records 只有自己能读写" on public.records;
create policy "records 只有自己能读写" on public.records for all
  using (uid = auth.uid()) with check (uid = auth.uid());
drop policy if exists "records 所有人可读" on public.records;
create policy "records 所有人可读" on public.records for select to authenticated using (true);

-- settings：只有自己
drop policy if exists "settings 只有自己能读写" on public.settings;
create policy "settings 只有自己能读写" on public.settings for all
  using (uid = auth.uid()) with check (uid = auth.uid());

-- ---------- 完成 ----------
-- 验证：select table_name from information_schema.tables
--       where table_schema='public' and table_name in
--       ('profiles','posts','records','settings','post_likes','post_comments');
--       应返回 6 行

-- ============================================================
-- 省流函数 lite_bundle()：一次请求返回 4 张表
-- 干什么用：App 在动态页每 8 秒同步一次，原来要打 4 个 REST 请求
--（profiles / posts / post_likes / post_comments），走反代就是 4 次云函数调用。
-- 建了这个函数后变成 1 次 RPC 调用，反代请求数直接降到 1/4。
-- 前端有自动降级：函数不存在时会退回四表并行，不会崩，只是没那么省。
-- ============================================================
create or replace function public.lite_bundle()
returns jsonb
language sql
stable
security definer
set search_path = public, pg_temp
as $$
  select jsonb_build_object(
    'profiles', coalesce((select jsonb_agg(to_jsonb(t)) from (
        select id, name, emoji from public.profiles order by id limit 200) t), '[]'::jsonb),
    'posts', coalesce((select jsonb_agg(to_jsonb(t)) from (
        select id, uid, text, workout, ts from public.posts order by ts desc limit 30) t), '[]'::jsonb),
    'likes', coalesce((select jsonb_agg(to_jsonb(t)) from (
        select post_id, uid, ts from public.post_likes limit 800) t), '[]'::jsonb),
    'comments', coalesce((select jsonb_agg(to_jsonb(t)) from (
        select id, post_id, uid, text, ts from public.post_comments order by ts asc limit 800) t), '[]'::jsonb)
  );
$$;

revoke all on function public.lite_bundle() from public;
grant execute on function public.lite_bundle() to authenticated;

-- 验证：登录后执行下面的语句应返回一个 json 对象（四个键，空库时都是 []）
--   select public.lite_bundle();
