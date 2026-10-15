-- Flujo de revision de visitas e integracion con solicitudes de CengiSalas.

insert into estados (nombre_estado)
select 'CANCELADO'
where not exists (
    select 1 from estados where upper(trim(nombre_estado)) = 'CANCELADO'
);

create table if not exists solicitud_reservas_cengisalas (
    id int not null auto_increment,
    id_solicitud int not null,
    request_code varchar(30) not null,
    space_id bigint not null,
    space_name varchar(160) not null,
    configuration_id bigint not null,
    configuration_name varchar(120) not null,
    hora_fin time not null,
    created_by varchar(191) null,
    created_at datetime not null default current_timestamp,
    primary key (id),
    unique key uq_visita_reserva_cengisalas (id_solicitud),
    constraint fk_visita_reserva_solicitud
        foreign key (id_solicitud) references solicitudes (id_solicitud)
        on delete cascade
);
