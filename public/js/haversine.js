/**
 * INFOCAMPO - Módulo Haversine
 * Calcula la distancia en metros entre dos coordenadas GPS.
 */
const Haversine = (() => {
    const R = 6371000; // Radio de la Tierra en metros

    /**
     * Convierte grados a radianes.
     */
    function toRad(deg) {
        return deg * (Math.PI / 180);
    }

    /**
     * Distancia en metros entre dos puntos (lat/lon).
     * @param {number} lat1
     * @param {number} lon1
     * @param {number} lat2
     * @param {number} lon2
     * @returns {number} distancia en metros
     */
    function distance(lat1, lon1, lat2, lon2) {
        const dLat = toRad(lat2 - lat1);
        const dLon = toRad(lon2 - lon1);
        const a =
            Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
            Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    /**
     * Devuelve el color del indicador según la distancia:
     *   - Verde  : <= 15 m
     *   - Amarillo: <= 50 m
     *   - Rojo   : > 50 m
     */
    function colorForDistance(meters) {
        if (meters <= 15) return 'green';
        if (meters <= 50) return 'yellow';
        return 'red';
    }

    /**
     * Formatea la distancia para UI.
     */
    function formatDistance(meters) {
        if (meters >= 1000) {
            return (meters / 1000).toFixed(2) + ' km';
        }
        return Math.round(meters) + ' m';
    }

    return { distance, colorForDistance, formatDistance };
})();
