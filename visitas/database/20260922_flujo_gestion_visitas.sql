-- Flujo completo del panel de gestion de visitas (MySQL / Aiven).

insert into estados (nombre_estado)
select 'ORGANIZANDO'
where not exists (select 1 from estados where upper(trim(nombre_estado)) = 'ORGANIZANDO');

insert into estados (nombre_estado)
select 'REVISION_DIRECTOR'
where not exists (select 1 from estados where upper(trim(nombre_estado)) = 'REVISION_DIRECTOR');

insert into estados (nombre_estado)
select 'NOTIFICADO'
where not exists (select 1 from estados where upper(trim(nombre_estado)) = 'NOTIFICADO');

create table if not exists solicitud_gestion_visita (
    id_solicitud int not null,
    agenda_nombre varchar(255) null,
    agenda_ruta varchar(500) null,
    notif_areas_at datetime null,
    notif_institucion_at datetime null,
    contacto_guardado_at datetime null,
    updated_at datetime not null default current_timestamp on update current_timestamp,
    primary key (id_solicitud),
    constraint fk_gestion_visita_solicitud
        foreign key (id_solicitud) references solicitudes (id_solicitud)
        on delete cascade
);

create table if not exists solicitud_fotos_visita (
    id_foto int not null auto_increment,
    id_solicitud int not null,
    nombre_archivo varchar(255) not null,
    ruta_archivo varchar(500) not null,
    created_at datetime not null default current_timestamp,
    primary key (id_foto),
    key idx_fotos_visita_solicitud (id_solicitud),
    constraint fk_foto_visita_solicitud
        foreign key (id_solicitud) references solicitudes (id_solicitud)
        on delete cascade
);

create table if not exists solicitud_eventos_visita (
    id_evento bigint not null auto_increment,
    id_solicitud int not null,
    tipo varchar(50) not null,
    descripcion varchar(500) not null,
    actor varchar(191) null,
    created_at datetime not null default current_timestamp,
    primary key (id_evento),
    key idx_eventos_visita_solicitud (id_solicitud, created_at),
    constraint fk_evento_visita_solicitud
        foreign key (id_solicitud) references solicitudes (id_solicitud)
        on delete cascade
);
