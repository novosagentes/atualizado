-- Cole e execute no SQL Editor do Supabase.
-- Depois cole SUPABASE_URL e SUPABASE_SERVICE_KEY em pagina/api/config.php

create table if not exists public.orders (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz not null default now(),
  paid_at timestamptz,
  status text not null default 'waiting_payment',
  transacao_id text unique,
  nome text,
  cpf text,
  email text,
  telefone text,
  cep text,
  cidade text,
  uf text,
  cargo text,
  produto text default 'Taxa de Inscricao',
  price_cents integer not null default 6348,
  marked_paid_by_admin boolean not null default false,
  utm_source text,
  utm_medium text,
  utm_campaign text,
  utm_campaign_name text,
  utm_adgroup text,
  utm_ad text,
  utm_keyword text,
  utm_term text,
  utm_matchtype text,
  utm_device text,
  utm_network text,
  utm_content text,
  gclid text,
  fbclid text,
  ttclid text
);

create table if not exists public.visits (
  id uuid primary key default gen_random_uuid(),
  created_at timestamptz not null default now(),
  session_id text,
  page text,
  utm_source text,
  utm_campaign text,
  utm_keyword text,
  utm_campaign_name text
);

create index if not exists orders_created_at_idx on public.orders (created_at desc);
create index if not exists orders_status_idx on public.orders (status);
create index if not exists orders_campaign_idx on public.orders (utm_campaign);
create index if not exists orders_keyword_idx on public.orders (utm_keyword);
create index if not exists visits_created_at_idx on public.visits (created_at desc);
create index if not exists visits_session_idx on public.visits (session_id);

alter table public.orders enable row level security;
alter table public.visits enable row level security;
