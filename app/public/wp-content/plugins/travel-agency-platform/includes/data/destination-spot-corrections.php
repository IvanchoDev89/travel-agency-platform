<?php
/**
 * Evidence-based corrections for the educational destination spots.
 *
 * Merged into TAP_Destination_Spots::seed_spots() and applied to existing
 * posts by TAP_Destination_Spots::apply_content_corrections(). Keeping the
 * corrections in one versioned map makes the seed and the database agree.
 *
 * Sources: SINAC (sinac.go.cr), UNESCO, ACG, CCT, municipal/ICT pages.
 * Verified: 2026-11-18.
 */
defined('ABSPATH') || exit;

function tap_destination_spot_corrections() {
    $fee = __('Hay tarifa de entrada, con precios distintos para residentes costarricenses y visitantes extranjeros. Consulta el precio y el horario vigentes en el sitio oficial antes de viajar.', 'travel-agency-platform');
    $free = __('Acceso libre sin tarifa de entrada; algunas actividades pueden tener costo. Consulta los detalles en el sitio oficial.', 'travel-agency-platform');

    return [
        'volcan-arenal' => [
            'area' => __('12 124 ha', 'travel-agency-platform'),
            'altitude' => __('400-1 755 msnm (cumbre del volcán: 1 670 m)', 'travel-agency-platform'),
            'created' => '1991',
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/ES/ac/acahn/pnva/Paginas/default.aspx',
        ],
        'tortuguero' => [
            'area' => __('76 937 ha (50 284 marinas / 26 653 terrestres)', 'travel-agency-platform'),
            'created' => '1970',
            'interest' => [
                __('Declarado parque nacional en 1970 (Decreto 1235-A) y reafirmado en 1975 (Ley 5680).', 'travel-agency-platform'),
                __('Es el sitio de anidación más importante del hemisferio para la tortuga verde (Chelonia mydas).', 'travel-agency-platform'),
                __('Solo se llega por agua o por aire: no hay carretera hasta el pueblo de Tortuguero.', 'travel-agency-platform'),
                __('La temporada de desove va de julio a octubre; los tours nocturnos son guiados y regulados.', 'travel-agency-platform'),
            ],
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/EN-US/ac/acto/pnt/pages/default.aspx',
        ],
        'corcovado' => [
            'area' => __('45 914 ha (42 560 terrestres / 3 354 marinas)', 'travel-agency-platform'),
            'altitude' => __('0-782 msnm', 'travel-agency-platform'),
            'created' => '1975',
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/es/ac/acosa/pnc/Paginas/default.aspx',
        ],
        'manuel-antonio' => [
            'area' => __('Aprox. 1 950-1 983 ha terrestres, más zona marina', 'travel-agency-platform'),
            'created' => '1972',
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/EN-US/ac/acopac/pnma/pages/default.aspx',
        ],
        'cahuita' => [
            'area' => __('Aprox. 1 068 ha terrestres / 22 400 marinas', 'travel-agency-platform'),
            'created' => '1978',
            'interest' => [
                __('El Monumento Nacional Cahuita se declaró en 1970 y se convirtió en parque nacional en 1978.', 'travel-agency-platform'),
                __('Protege el arrecife de coral más importante del Caribe costarricense y selva que llega hasta el mar.', 'travel-agency-platform'),
                __('Las 600 ha del arrecife son solo una parte de la extensión marina total del parque.', 'travel-agency-platform'),
            ],
            'fee' => $free,
            'official_url' => 'https://www.sinac.go.cr/es/ac/aclac/pnc/paginas/default.aspx',
        ],
        'chirripo' => [
            'area' => __('50 150 ha', 'travel-agency-platform'),
            'created' => '1975',
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/es/ac/aclap/pnch/paginas/default.aspx',
        ],
        'isla-del-coco' => [
            'area' => __('203 483 ha (2 330 terrestres / 201 153 marinas)', 'travel-agency-platform'),
            'created' => '1978',
            'interest' => [
                __('Declarado parque nacional en 1978 y Patrimonio Mundial de la UNESCO desde 1997.', 'travel-agency-platform'),
                __('Está a unos 550 km de la costa del Pacífico y solo se visita con permiso y guardaparques.', 'travel-agency-platform'),
                __('Sus aguas protegen tiburones martillo, mantarrayas y una fauna oceánica excepcional.', 'travel-agency-platform'),
                __('La extensión marina del parque es de unas 201 000 ha; la cifra de 972 000 corresponde a una unidad obsoleta.', 'travel-agency-platform'),
            ],
            'fee' => $fee,
            'official_url' => 'https://whc.unesco.org/en/list/820/',
        ],
        'palo-verde' => [
            'area' => __('19 800 ha', 'travel-agency-platform'),
            'created' => '1978',
            'interest' => [
                __('Creado como reserva biológica en 1978 y elevado a parque nacional en 1980.', 'travel-agency-platform'),
                __('Es un humedal de importancia Ramsar desde 1991 y uno de los mejores lugares del país para aves acuáticas.', 'travel-agency-platform'),
            ],
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/ES/ac/acat/pnpv/Paginas/default.aspx',
        ],
        'rincon-de-la-vieja' => [
            'area' => __('14 300 ha', 'travel-agency-platform'),
            'altitude' => __('Hasta 1 916 msnm (cráter von Seebach)', 'travel-agency-platform'),
            'created' => '1973',
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/es/ac/acg/pnrv/paginas/default.aspx',
        ],
        'santa-rosa' => [
            'title' => __('Parque Nacional Santa Rosa', 'travel-agency-platform'),
            'area' => __('Aprox. 38 674 ha terrestres, más zona marina', 'travel-agency-platform'),
            'created' => '1971',
            'interest' => [
                __('El parque nacional se creó en 1971; la hacienda había sido declarado monumento nacional en 1966.', 'travel-agency-platform'),
                __('La batalla de Santa Rosa de 1855 conmemora la defensa de la independencia nacional.', 'travel-agency-platform'),
                __('Forma parte del Área de Conservación Guanacaste, Patrimonio Mundial de la UNESCO desde 1999.', 'travel-agency-platform'),
                __('Protege el bosque seco y uno de los manglares más extensos del Pacífico costarricense.', 'travel-agency-platform'),
            ],
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/ES/ac/acg/pnsr/Paginas/default.aspx',
        ],
        'irazu' => [
            'area' => __('2 309 ha', 'travel-agency-platform'),
            'created' => '1955',
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/ES/ac/accvc/pnvi/Paginas/default.aspx',
        ],
        'poas' => [
            'area' => __('6 506 ha', 'travel-agency-platform'),
            'altitude' => __('1 200-2 708 msnm', 'travel-agency-platform'),
            'created' => '1971',
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/es/ac/accvc/pnvp/paginas/default.aspx',
        ],
        'tenorio' => [
            'area' => __('12 872 ha', 'travel-agency-platform'),
            'altitude' => __('Hasta 1 916 msnm', 'travel-agency-platform'),
            'created' => '1995',
            'interest' => [
                __('El parque se declaró en 1995 y protege el bosque nuboso y húmedo del volcán Tenorio.', 'travel-agency-platform'),
                __('El Teñidero es el punto donde el agua del Río Celeste adquiere su color turquesa.', 'travel-agency-platform'),
                __('El acceso tiene cupo limitado por hora; conviene reservar con anticipación.', 'travel-agency-platform'),
            ],
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/es/ac/acat/pnvt/paginas/default.aspx',
        ],
        'marino-ballena' => [
            'area' => __('5 375 ha marinas / 171 ha terrestres', 'travel-agency-platform'),
            'created' => '1989',
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/ES/ac/acosa/pnmb/Paginas/default.aspx',
        ],
        'braulio-carrillo' => [
            'area' => __('50 000 ha', 'travel-agency-platform'),
            'altitude' => __('34-2 906 msnm', 'travel-agency-platform'),
            'created' => '1978',
            'interest' => [
                __('Cubre un gradiente altitudinal excepcional, desde tierras bajas hasta la cumbre del volcán Barva (2 906 m).', 'travel-agency-platform'),
                __('Protege una de las mayores extensiones de bosque húmedo tropical primario de Centroamérica.', 'travel-agency-platform'),
                __('El área se amplió en 2015 hasta alcanzar unas 50 000 hectáreas.', 'travel-agency-platform'),
            ],
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/es/ac/accvc/pnbc/paginas/default.aspx',
        ],
        'tapantial' => [
            'title' => __('Parque Nacional Tapantí–Macizo Cerro de la Muerte', 'travel-agency-platform'),
            'area' => __('58 495 ha', 'travel-agency-platform'),
            'altitude' => __('700-3 491 msnm (sector turístico: 1 220-2 560 m)', 'travel-agency-platform'),
            'created' => '1982',
            'content' => __('Tapantí–Macizo Cerro de la Muerte protege el bosque nuboso más extenso de la sierra volcánica central. El quetzal anida en el dosel y se observa con guía desde el mirador. El parque se formó al fusionarse la Reserva Forestal Río Macho y el Refugio de Fauna Silvestre Tapantí.', 'travel-agency-platform'),
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/es/ac/accvc/pntpmm/paginas/default.aspx',
        ],
        'juan-santamaria' => [
            'title' => __('Parque Nacional del Agua Juan Castro Blanco', 'travel-agency-platform'),
            'excerpt' => __('Bosque nuboso y cabeceras de cinco ríos en la cordillera central de Alajuela.', 'travel-agency-platform'),
            'area' => __('14 453 ha', 'travel-agency-platform'),
            'created' => '1992',
            'ecosystem' => __('Bosque nuboso montano y nacientes de agua', 'travel-agency-platform'),
            'interest' => [
                __('Creado en 1992 por la Ley 7297 para proteger las cabeceras de cinco ríos.', 'travel-agency-platform'),
                __('El nombre Juan Santamaría corresponde al parque y museo urbanos de Alajuela y al aeropuerto internacional, no a un área silvestre protegida.', 'travel-agency-platform'),
                __('El parque recibe visitación limitada; conviene consultar las condiciones de acceso antes de viajar.', 'travel-agency-platform'),
            ],
            'biodiversity' => [
                __('Aves de bosque nuboso y especies de altura en la cordillera central', 'travel-agency-platform'),
                __('Anfibios y epífitas asociados a la neblina permanente', 'travel-agency-platform'),
            ],
            'activities' => [
                __('Observación de aves y caminatas en los senderos del parque', 'travel-agency-platform'),
                __('Visita a las nacientes que abastecen de agua a la región', 'travel-agency-platform'),
            ],
            'fee' => $free,
            'official_url' => 'https://www.sinac.go.cr/ES/ac/acahn/Paginas/default.aspx',
        ],
        'guayabo' => [
            'area' => __('233 ha', 'travel-agency-platform'),
            'created' => '1973',
            'altitude' => __('990-1 300 msnm', 'travel-agency-platform'),
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/es/ac/accvc/mng/paginas/default.aspx',
        ],
        'gandoca-manzanillo' => [
            'title' => __('Refugio Nacional Mixto de Vida Silvestre Gandoca-Manzanillo', 'travel-agency-platform'),
            'area' => __('10 452 ha (5 092 terrestres / 5 360 marinas)', 'travel-agency-platform'),
            'created' => '1985',
            'fee' => $free,
            'official_url' => 'https://www.sinac.go.cr/ES/ac/aclac/rnvsgm/Paginas/default.aspx',
        ],
        'monteverde' => [
            'area' => __('4 125 ha', 'travel-agency-platform'),
            'created' => '1972',
            'interest' => [
                __('La reserva se creó en 1972 con una primera donación de 328 hectáreas.', 'travel-agency-platform'),
                __('Hoy protege 4 125 hectáreas de bosque nuboso; 1 600 m es su altitud, no su superficie.', 'travel-agency-platform'),
                __('Es una reserva privada administrada por el Centro Científico Tropical, no un parque de SINAC.', 'travel-agency-platform'),
            ],
            'content' => __('La reserva nació en 1972 cuando cafetaleros y conservacionistas acordaron dejar áreas sin talar a cambio de protección del agua. Hoy conserva 4 125 hectáreas de bosque nuboso con neblina permanente, donde el quetzal y las aves del dosel son la principal atracción. Las pasarelas permiten recorrer el bosque sin pisar el suelo.', 'travel-agency-platform'),
            'fee' => $fee,
            'official_url' => 'https://cct.or.cr/sirep/reservabiologicabosquenubosomonteverdecct',
        ],
        'lomas-barbudal' => [
            'area' => __('2 646 ha', 'travel-agency-platform'),
            'created' => '1986',
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/ES/ac/acat/Paginas/default.aspx',
        ],
        'cabo-blanco' => [
            'area' => __('Aprox. 1 270 ha terrestres más zona marina', 'travel-agency-platform'),
            'created' => '1963',
            'interest' => [
                __('Declarada en 1963, fue la primera área silvestre protegida y primera reserva natural absoluta del país.', 'travel-agency-platform'),
                __('La iniciativa de Olof Wessberg y Karen Morgenson dio origen al sistema de áreas protegidas de Costa Rica.', 'travel-agency-platform'),
                __('Protege bosque seco, costa rocosa y una zona marina con tortugas verdes.', 'travel-agency-platform'),
            ],
            'content' => __('Cabo Blanco fue declarada reserva absoluta en 1963, la primera del país, gracias a la iniciativa de Olof Wessberg y Karen Morgenson. Hoy protege unas 1 270 hectáreas de bosque seco, costa rocosa y mar. La playa donde nadaron los Beatles recibe tortugas verdes cada temporada.', 'travel-agency-platform'),
            'fee' => $fee,
            'official_url' => 'https://www.visitcostarica.com/blog/inside-cabo-blanco-absolute-natural-reserve',
        ],
        'cabo-papagayo' => [
            'title' => __('Cabo Papagayo y Golfo de Papagayo', 'travel-agency-platform'),
            'created' => '',
            'area' => __('Promontorio costero del golfo de Papagayo, Guanacaste', 'travel-agency-platform'),
            'interest' => [
                __('Cabo Papagayo está en el golfo de Papagayo, al norte de la península de Nicoya, en Guanacaste.', 'travel-agency-platform'),
                __('Es un promontorio natural: no tiene año de creación ni categoría de área protegida propia.', 'travel-agency-platform'),
                __('El sector marino cercano forma parte del Área de Conservación Guanacaste.', 'travel-agency-platform'),
            ],
            'fee' => $free,
            'official_url' => 'https://www.acguanacaste.ac.cr/biodesarrollo/sector-marino',
        ],
        'cerro-de-la-muerte' => [
            'created' => '',
            'altitude' => __('Cumbre Cerro Buenavista 3 451 msnm; paso de la Ruta 2 a ~3 345 msnm', 'travel-agency-platform'),
            'area' => __('Macizo de la cordillera de Talamanca, sobre la Carretera Interamericana Sur (Ruta 2)', 'travel-agency-platform'),
            'accessibility' => __('Se llega en vehículo por la Ruta 2 o Carretera Interamericana Sur; el mirador no tiene rampa.', 'travel-agency-platform'),
            'interest' => [
                __('El acceso es por la Ruta 2 o Carretera Interamericana Sur; la Ruta 32 conduce a Limón.', 'travel-agency-platform'),
                __('Cerro Buenavista, su punto más alto, alcanza unos 3 451 msnm.', 'travel-agency-platform'),
                __('Es un paso de montaña natural en la cordillera de Talamanca: no tiene año de creación.', 'travel-agency-platform'),
            ],
            'fee' => $free,
            'official_url' => 'https://es.wikipedia.org/wiki/Cerro_de_la_Muerte',
        ],
        'la-fortuna' => [
            'created' => __('Distrito creado el 5 de febrero de 1952', 'travel-agency-platform'),
            'fee' => $free,
            'official_url' => 'https://es.wikipedia.org/wiki/La_Fortuna_(Costa_Rica)',
        ],
        'montezuma' => [
            'created' => '',
            'interest' => [
                __('Antiguo puerto de exportación agrícola de los años 1940-50 y luego pueblo pesquero.', 'travel-agency-platform'),
                __('Se consolidó como destino de surf y bohemio desde los años 70-80.', 'travel-agency-platform'),
            ],
            'fee' => $free,
            'official_url' => 'https://es.wikipedia.org/wiki/Montezuma,_Costa_Rica',
        ],
        'nuevo-arenal' => [
            'created' => __('Embalse construido en 1979; Nuevo Arenal surgió con el traslado de la población', 'travel-agency-platform'),
            'area' => __('Cantón de Tilarán, Guanacaste, en el límite con Alajuela', 'travel-agency-platform'),
            'fee' => $free,
            'official_url' => 'https://www.tilaran.go.cr/articulo/193/arenal',
        ],
        'peninsula-nicoya' => [
            'created' => '1999',
            'area' => __('El ACG abarca 147 000 ha (1 470 km²); la península de Nicoya es mucho más extensa', 'travel-agency-platform'),
            'interest' => [
                __('El Área de Conservación Guanacaste es Patrimonio Mundial desde 1999 (ampliación en 2004), lista 928 de la UNESCO.', 'travel-agency-platform'),
                __('Comprende Santa Rosa, Guanacaste, Rincón de la Vieja, Bahía Junquillal y Santa Elena, en el noroeste continental.', 'travel-agency-platform'),
                __('La inscripción de la UNESCO no incluye la península de Nicoya.', 'travel-agency-platform'),
            ],
            'official_url' => 'https://whc.unesco.org/en/list/928/',
        ],
        'puerto-jimenez' => [
            'title' => __('Puerto Jiménez y la Península de Osa', 'travel-agency-platform'),
            'created' => __('Distrito creado el 10 de junio de 1949', 'travel-agency-platform'),
            'area' => __('Costa este de la península de Osa, a orillas del golfo Dulce', 'travel-agency-platform'),
            'content' => __('Puerto Jiménez es la principal puerta de entrada a la península de Osa. Está en la costa este, a orillas del golfo Dulce, y el parque nacional Corcovado queda a unos 10 km del pueblo. Al sur comienza la Bahía de Drake y su reserva marina.', 'travel-agency-platform'),
            'fee' => $free,
            'official_url' => 'https://www.gobiernolocalosa.go.cr/mobile/historia.html',
        ],
        'rio-celeste' => [
            'title' => __('Río Celeste y el Teñidero', 'travel-agency-platform'),
            'created' => __('Parque nacional declarado en 1995', 'travel-agency-platform'),
            'altitude' => __('Hasta 1 800 msnm en el parque (cumbre del Tenorio: 1 916 m)', 'travel-agency-platform'),
            'interest' => [
                __('El Teñidero es el punto donde el agua adquiere su característico color turquesa por minerales y dispersión de la luz.', 'travel-agency-platform'),
                __('El sitio está dentro del Parque Nacional Volcán Tenorio, declarado en 1995.', 'travel-agency-platform'),
                __('El acceso tiene cupo limitado por hora; conviene reservar con anticipación.', 'travel-agency-platform'),
            ],
            'fee' => $fee,
            'official_url' => 'https://www.sinac.go.cr/es/ac/acat/pnvt/paginas/default.aspx',
        ],
        'samara' => [
            'title' => __('Sámara', 'travel-agency-platform'),
            'created' => __('Distrito creado el 26 de noviembre de 1971', 'travel-agency-platform'),
            'area' => __('Playa de Sámara, cantón de Nicoya, Guanacaste', 'travel-agency-platform'),
            'conservation' => __('La arribada de tortugas oliváceas ocurre en Ostional, al norte; la playa de Sámara no forma parte de ese refugio. Al sur está Playa Camaronal.', 'travel-agency-platform'),
            'content' => __('Sámara es un pueblo de surf en la península de Nicoya con una playa de unos 1,4 km. La desembocadura del río Sámara forma un estuario con gran variedad de aves. La arribada de tortugas oliváceas ocurre en Ostional, al norte, y no en la playa de Sámara.', 'travel-agency-platform'),
            'interest' => [
                __('Sámara es un distrito del cantón de Nicoya creado en 1971.', 'travel-agency-platform'),
                __('Playa Ostional, al norte, es el sitio de anidación de tortugas oliváceas; la playa de Sámara no forma parte de ese refugio.', 'travel-agency-platform'),
                __('Al sur se encuentra Playa Camaronal, otro sitio de desove de tortugas.', 'travel-agency-platform'),
            ],
            'fee' => $free,
            'official_url' => 'https://es.wikipedia.org/wiki/S%C3%A1mara_(Costa_Rica)',
        ],
    ];
}
