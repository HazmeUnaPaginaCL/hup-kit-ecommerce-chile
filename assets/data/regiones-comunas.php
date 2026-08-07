<?php
/**
 * Regiones y Comunas Oficiales de Chile
 * Base territorial Chile para HUP - Kit Ecommerce Chile
 * Desarrollado por HazmeUnaPagina.cl
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function hupkec_get_regions_base() {

    return [

        'AP' => 'Región de Arica y Parinacota',
        'TA' => 'Región de Tarapacá',
        'AN' => 'Región de Antofagasta',
        'AT' => 'Región de Atacama',
        'CO' => 'Región de Coquimbo',
        'VS' => 'Región de Valparaíso',
        'RM' => 'Región Metropolitana de Santiago',
        'LI' => 'Región del Libertador General Bernardo O\'Higgins',
        'ML' => 'Región del Maule',
        'NB' => 'Región de Ñuble',
        'BI' => 'Región del Biobío',
        'AR' => 'Región de La Araucanía',
        'LR' => 'Región de Los Ríos',
        'LL' => 'Región de Los Lagos',
        'AI' => 'Región de Aysén del General Carlos Ibáñez del Campo',
        'MA' => 'Región de Magallanes y de la Antártica Chilena',
    ];
}

function hupkec_get_comunas_base() {

    return [

        /* =========================
           ARICA Y PARINACOTA
        ========================= */

        'AP' => [
            'Arica',
            'Camarones',
            'Putre',
            'General Lagos'
        ],

        /* =========================
           TARAPACÁ
        ========================= */

        'TA' => [
            'Iquique',
            'Alto Hospicio',
            'Pozo Almonte',
            'Camiña',
            'Colchane',
            'Huara',
            'Pica'
        ],

        /* =========================
           ANTOFAGASTA
        ========================= */

        'AN' => [
            'Antofagasta',
            'Mejillones',
            'Sierra Gorda',
            'Taltal',
            'Calama',
            'Ollagüe',
            'San Pedro de Atacama',
            'Tocopilla',
            'María Elena'
        ],

        /* =========================
           ATACAMA
        ========================= */

        'AT' => [
            'Copiapó',
            'Caldera',
            'Tierra Amarilla',
            'Chañaral',
            'Diego de Almagro',
            'Vallenar',
            'Freirina',
            'Huasco',
            'Alto del Carmen'
        ],

        /* =========================
           COQUIMBO
        ========================= */

        'CO' => [
            'La Serena',
            'Coquimbo',
            'Andacollo',
            'La Higuera',
            'Vicuña',
            'Paihuano',
            'Ovalle',
            'Monte Patria',
            'Combarbalá',
            'Punitaqui',
            'Río Hurtado',
            'Illapel',
            'Canela',
            'Los Vilos',
            'Salamanca'
        ],

        /* =========================
           VALPARAÍSO
        ========================= */

        'VS' => [
            'Valparaíso',
            'Viña del Mar',
            'Concón',
            'Quintero',
            'Puchuncaví',
            'Casablanca',
            'Juan Fernández',
            'Isla de Pascua',
            'Quillota',
            'La Cruz',
            'La Calera',
            'Nogales',
            'Hijuelas',
            'San Antonio',
            'Cartagena',
            'El Tabo',
            'El Quisco',
            'Algarrobo',
            'Santo Domingo',
            'San Felipe',
            'Catemu',
            'Llaillay',
            'Panquehue',
            'Putaendo',
            'Santa María',
            'Los Andes',
            'Calle Larga',
            'Rinconada',
            'San Esteban',
            'Petorca',
            'Cabildo',
            'La Ligua',
            'Papudo',
            'Zapallar',
            'Limache',
            'Olmué',
            'Villa Alemana',
            'Quilpué'
        ],

        /* =========================
           METROPOLITANA
        ========================= */

        'RM' => [
            'Santiago',
            'Cerrillos',
            'Cerro Navia',
            'Conchalí',
            'El Bosque',
            'Estación Central',
            'Huechuraba',
            'Independencia',
            'La Cisterna',
            'La Florida',
            'La Granja',
            'La Pintana',
            'La Reina',
            'Las Condes',
            'Lo Barnechea',
            'Lo Espejo',
            'Lo Prado',
            'Macul',
            'Maipú',
            'Ñuñoa',
            'Pedro Aguirre Cerda',
            'Peñalolén',
            'Providencia',
            'Pudahuel',
            'Quilicura',
            'Quinta Normal',
            'Recoleta',
            'Renca',
            'San Joaquín',
            'San Miguel',
            'San Ramón',
            'Vitacura',
            'Puente Alto',
            'Pirque',
            'San José de Maipo',
            'Colina',
            'Lampa',
            'Tiltil',
            'San Bernardo',
            'Buin',
            'Calera de Tango',
            'Paine',
            'Melipilla',
            'Alhué',
            'Curacaví',
            'María Pinto',
            'San Pedro',
            'Talagante',
            'El Monte',
            'Isla de Maipo',
            'Padre Hurtado',
            'Peñaflor'
        ],

        /* =========================
           O'HIGGINS
        ========================= */

        'LI' => [
            'Rancagua',
            'Codegua',
            'Coinco',
            'Coltauco',
            'Doñihue',
            'Graneros',
            'Las Cabras',
            'Machalí',
            'Malloa',
            'Mostazal',
            'Olivar',
            'Peumo',
            'Pichidegua',
            'Quinta de Tilcoco',
            'Rengo',
            'Requínoa',
            'San Vicente',
            'Pichilemu',
            'La Estrella',
            'Litueche',
            'Marchihue',
            'Navidad',
            'Paredones',
            'San Fernando',
            'Chépica',
            'Chimbarongo',
            'Lolol',
            'Nancagua',
            'Palmilla',
            'Peralillo',
            'Placilla',
            'Pumanque',
            'Santa Cruz'
        ],

        /* =========================
           MAULE
        ========================= */

        'ML' => [
            'Talca',
            'Constitución',
            'Curepto',
            'Empedrado',
            'Maule',
            'Pelarco',
            'Pencahue',
            'Río Claro',
            'San Clemente',
            'San Rafael',
            'Curicó',
            'Hualañé',
            'Licantén',
            'Molina',
            'Rauco',
            'Romeral',
            'Sagrada Familia',
            'Teno',
            'Vichuquén',
            'Linares',
            'Colbún',
            'Longaví',
            'Parral',
            'Retiro',
            'San Javier',
            'Villa Alegre',
            'Yerbas Buenas',
            'Cauquenes',
            'Chanco',
            'Pelluhue'
        ],

        /* =========================
           ÑUBLE
        ========================= */

        'NB' => [
            'Bulnes',
            'Chillán',
            'Chillán Viejo',
            'El Carmen',
            'Pemuco',
            'Pinto',
            'Quillón',
            'San Ignacio',
            'Yungay',
            'Cobquecura',
            'Coelemu',
            'Ninhue',
            'Portezuelo',
            'Quirihue',
            'Ránquil',
            'Trehuaco',
            'Coihueco',
            'Ñiquén',
            'San Carlos',
            'San Fabián',
            'San Nicolás'
        ],

        /* =========================
           BIOBÍO
        ========================= */

        'BI' => [
            'Concepción',
            'Coronel',
            'Chiguayante',
            'Florida',
            'Hualqui',
            'Lota',
            'Penco',
            'San Pedro de la Paz',
            'Santa Juana',
            'Talcahuano',
            'Tomé',
            'Hualpén',
            'Los Ángeles',
            'Antuco',
            'Cabrero',
            'Laja',
            'Mulchén',
            'Nacimiento',
            'Negrete',
            'Quilleco',
            'Quilaco',
            'San Rosendo',
            'Santa Bárbara',
            'Tucapel',
            'Yumbel',
            'Alto Biobío',
            'Arauco',
            'Cañete',
            'Contulmo',
            'Curanilahue',
            'Lebu',
            'Los Álamos',
            'Tirúa'
        ],

        /* =========================
           ARAUCANÍA
        ========================= */

        'AR' => [
            'Temuco',
            'Carahue',
            'Cunco',
            'Curarrehue',
            'Freire',
            'Galvarino',
            'Gorbea',
            'Lautaro',
            'Loncoche',
            'Melipeuco',
            'Nueva Imperial',
            'Padre Las Casas',
            'Perquenco',
            'Pitrufquén',
            'Pucón',
            'Saavedra',
            'Teodoro Schmidt',
            'Toltén',
            'Vilcún',
            'Villarrica',
            'Cholchol',
            'Angol',
            'Collipulli',
            'Curacautín',
            'Ercilla',
            'Lonquimay',
            'Los Sauces',
            'Lumaco',
            'Purén',
            'Renaico',
            'Traiguén',
            'Victoria'
        ],

        /* =========================
           LOS RÍOS
        ========================= */

        'LR' => [
            'Valdivia',
            'Corral',
            'Lanco',
            'Los Lagos',
            'Máfil',
            'Mariquina',
            'Paillaco',
            'Panguipulli',
            'La Unión',
            'Futrono',
            'Lago Ranco',
            'Río Bueno'
        ],

        /* =========================
           LOS LAGOS
        ========================= */

        'LL' => [
            'Puerto Montt',
            'Calbuco',
            'Cochamó',
            'Fresia',
            'Frutillar',
            'Los Muermos',
            'Llanquihue',
            'Maullín',
            'Puerto Varas',
            'Castro',
            'Ancud',
            'Chonchi',
            'Curaco de Vélez',
            'Dalcahue',
            'Puqueldón',
            'Queilén',
            'Quellón',
            'Quemchi',
            'Quinchao',
            'Osorno',
            'Puerto Octay',
            'Purranque',
            'Puyehue',
            'Río Negro',
            'San Juan de la Costa',
            'San Pablo',
            'Chaitén',
            'Futaleufú',
            'Hualaihué',
            'Palena'
        ],

        /* =========================
           AYSÉN
        ========================= */

        'AI' => [
            'Coyhaique',
            'Lago Verde',
            'Aysén',
            'Cisnes',
            'Guaitecas',
            'Río Ibáñez',
            'Chile Chico',
            'Cochrane',
            'O’Higgins',
            'Tortel'
        ],

        /* =========================
           MAGALLANES
        ========================= */

        'MA' => [
            'Punta Arenas',
            'Laguna Blanca',
            'Río Verde',
            'San Gregorio',
            'Cabo de Hornos',
            'Antártica',
            'Porvenir',
            'Primavera',
            'Timaukel',
            'Natales',
            'Torres del Paine'
        ]

    ];
}