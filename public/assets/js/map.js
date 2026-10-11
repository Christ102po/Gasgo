
const SIARGAO_BOUNDS = L.latLngBounds(
    [9.55, 125.95],   // Southwest
    [10.05, 126.35]   // Northeast
);


const SIARGAO_CENTER = [
    9.78,
    126.16
];


const SIARGAO_DEFAULT_ZOOM = 11;


function initGasMap(stations) {

  
    if (!Array.isArray(stations)) {
        stations = [];
    }


    const map = L.map('map', {

        center: SIARGAO_CENTER,
        zoom: SIARGAO_DEFAULT_ZOOM,
        maxBounds: SIARGAO_BOUNDS,
        maxBoundsViscosity: 1.0,
        minZoom: 10,
        maxZoom: 18

    });


    const streetLayer = L.tileLayer(
        'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
            maxNativeZoom: 19,
            maxZoom: 21,
            attribution: '&copy; OpenStreetMap contributors'
        }
    );

    const satelliteLayer = L.tileLayer(
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        {
            maxNativeZoom: 19,
            maxZoom: 21,
            attribution: 'Imagery &copy; Esri and contributors'
        }
    );

    const terrainLayer = L.tileLayer(
        'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
        {
            maxNativeZoom: 17,
            maxZoom: 21,
            attribution: 'Map data &copy; OpenStreetMap contributors, SRTM | Map style &copy; OpenTopoMap'
        }
    );

    const humanitarianLayer = L.tileLayer(
        'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
        {
            maxNativeZoom: 19,
            maxZoom: 21,
            attribution: '&copy; OpenStreetMap contributors, Tiles style by HOT'
        }
    );

    streetLayer.addTo(map);

    L.control.layers(
        {
            'Street': streetLayer,
            'Satellite': satelliteLayer,
            'Terrain': terrainLayer,
            'Humanitarian': humanitarianLayer
        },
        null,
        { position: 'topright', collapsed: true }
    ).addTo(map);


    L.rectangle(
        SIARGAO_BOUNDS,
        {
            weight: 1,
            fill: false,
            interactive: false
        }
    ).addTo(map);


    const stationMarkers = {};


    stations.forEach(function (s) {


        const lat = Number(s.latitude);
        const lng = Number(s.longitude);


        if (
            !Number.isFinite(lat) ||
            !Number.isFinite(lng)
        ) {
            return;
        }


        if (
            !SIARGAO_BOUNDS.contains([
                lat,
                lng
            ])
        ) {
            return;
        }


        const marker = L.marker([
            lat,
            lng
        ]).addTo(map);


        const stationName =
            escapeHtml(
                s.name || 'Gasoline Station'
            );


        const barangay =
            escapeHtml(
                s.barangay || ''
            );


        const unleadedPrice =
            s.unleaded_price !== null &&
            s.unleaded_price !== undefined &&
            s.unleaded_price !== ''
                ? escapeHtml(s.unleaded_price)
                : 'N/A';


        const stationUrl =
            'station.php?id=' +
            encodeURIComponent(s.id);


        const popupContent =

            '<div class="gasgo-map-popup">' +

                '<strong>' +
                    stationName +
                '</strong>' +

                '<br>' +

                barangay +

                '<br>' +

                'Unleaded: ₱' +
                unleadedPrice +

                '<br><br>' +

                '<a href="' +
                    stationUrl +
                '">' +

                    'View details' +

                '</a>' +

            '</div>';


        marker.bindPopup(
            popupContent
        );


        stationMarkers[
            String(s.id)
        ] = marker;

    });


    window.gasGoMap = map;


    window.gasGoStationMarkers =
        stationMarkers;


    if (
        navigator.geolocation
    ) {

        navigator.geolocation.getCurrentPosition(

            function (pos) {


                const lat =
                    Number(
                        pos.coords.latitude
                    );

                const lng =
                    Number(
                        pos.coords.longitude
                    );


                if (
                    !Number.isFinite(lat) ||
                    !Number.isFinite(lng)
                ) {
                    return;
                }


                if (
                    !SIARGAO_BOUNDS.contains([
                        lat,
                        lng
                    ])
                ) {
                    return;
                }


                L.circleMarker(
                    [
                        lat,
                        lng
                    ],
                    {
                        radius: 8
                    }
                )
                .addTo(map)
                .bindPopup(
                    'Your approximate location'
                );

            },

            function () {


            }

        );

    }


    return map;

}


function goToStationOnMap(station) {


    if (!station) {
        return;
    }


    const map =
        window.gasGoMap;


    const markers =
        window.gasGoStationMarkers;


    if (!map) {

        console.error(
            'GasGo map is not initialized.'
        );

        return;
    }


    const lat =
        Number(
            station.latitude
        );

    const lng =
        Number(
            station.longitude
        );


    if (
        !Number.isFinite(lat) ||
        !Number.isFinite(lng)
    ) {

        console.error(
            'Invalid station coordinates.'
        );

        return;
    }


    if (
        !SIARGAO_BOUNDS.contains([
            lat,
            lng
        ])
    ) {

        console.error(
            'Station is outside Siargao Island.'
        );

        return;
    }


    map.setView(
        [
            lat,
            lng
        ],
        17,
        {
            animate: true
        }
    );

    if (
        markers &&
        markers[
            String(station.id)
        ]
    ) {

        markers[
            String(station.id)
        ].openPopup();

    }

}


function escapeHtml(value) {

    return String(
        value ?? ''
    ).replace(
        /[&<>"']/g,
        function (character) {

            return {

                '&': '&amp;',

                '<': '&lt;',

                '>': '&gt;',

                '"': '&quot;',

                "'": '&#039;'

            }[character];

        }
    );

}