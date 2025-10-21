# ADR-XXX: [Título de la Decisión]

**Fecha:** YYYY-MM-DD
**Estado:** Propuesto | Aceptado | Rechazado | Supersedido por ADR-YYY | Deprecated
**Decidido por:** [Nombre del equipo/persona]
**Stakeholders:** [Quiénes participaron en la decisión]

---

## Contexto

[Describe el contexto que llevó a necesitar esta decisión. ¿Qué problema estamos intentando resolver? ¿Qué restricciones tenemos? ¿Qué nos llevó a este punto?]

**Ejemplo:**
> Necesitamos decidir cómo implementar WebSockets para la sincronización en tiempo real del canvas en Pictionary. El sistema debe soportar hasta 50 salas simultáneas con 10 jugadores cada una, y la latencia debe ser menor a 500ms.

---

## Decisión

[Describe claramente qué se decidió hacer. Sé específico y conciso.]

**Ejemplo:**
> Usaremos Laravel Reverb como servidor WebSocket para la sincronización en tiempo real.

---

## Opciones Consideradas

### Opción A: [Nombre de la opción]

**Descripción:** [Breve descripción de esta opción]

**Pros:**
- ✅ [Ventaja 1]
- ✅ [Ventaja 2]
- ✅ [Ventaja 3]

**Contras:**
- ❌ [Desventaja 1]
- ❌ [Desventaja 2]
- ❌ [Desventaja 3]

**Costo/Esfuerzo:** [Bajo/Medio/Alto - Tiempo estimado]

**Ejemplo:**
```php
// Si aplica, muestra un ejemplo de código
```

---

### Opción B: [Nombre de la opción]

**Descripción:** [Breve descripción]

**Pros:**
- ✅ [Ventaja 1]
- ✅ [Ventaja 2]

**Contras:**
- ❌ [Desventaja 1]
- ❌ [Desventaja 2]

**Costo/Esfuerzo:** [Bajo/Medio/Alto]

---

### Opción C: [Nombre de la opción]

[Similar estructura]

---

## Razones de la Decisión

[Explica por qué se eligió la opción seleccionada sobre las demás. Sé específico sobre qué criterios fueron más importantes.]

**Criterios de evaluación:**
1. **[Criterio 1]:** [Por qué es importante y cómo influyó]
2. **[Criterio 2]:** [Por qué es importante y cómo influyó]
3. **[Criterio 3]:** [Por qué es importante y cómo influyó]

**Ejemplo:**
> Elegimos Laravel Reverb porque:
> 1. **Integración nativa:** Al ser oficial de Laravel, no requiere configuración adicional compleja
> 2. **Costo:** Es gratuito y open-source, vs Pusher que requiere suscripción
> 3. **Rendimiento:** Soporta ~1000 conexiones, suficiente para nuestro MVP de 500 usuarios concurrentes
> 4. **Mantenimiento:** El equipo de Laravel lo mantiene activamente

---

## Consecuencias

### Positivas
- ✅ [Consecuencia positiva 1]
- ✅ [Consecuencia positiva 2]
- ✅ [Consecuencia positiva 3]

### Negativas
- ❌ [Consecuencia negativa 1 - y cómo la mitigaremos]
- ❌ [Consecuencia negativa 2 - y cómo la mitigaremos]

### Neutras
- ℹ️ [Consecuencia neutra 1]
- ℹ️ [Consecuencia neutra 2]

**Ejemplo:**
> **Positivas:**
> - ✅ No requiere instalación de servicios externos
> - ✅ Sin costos adicionales
> - ✅ Documentación oficial en español
>
> **Negativas:**
> - ❌ Límite de ~1000 conexiones (mitigación: suficiente para MVP, si crece migraremos a Pusher)
> - ❌ Requiere servidor Node.js adicional (mitigación: ya usamos Node para Vite)
>
> **Neutras:**
> - ℹ️ Nueva tecnología para el equipo (curva de aprendizaje)

---

## Impacto

### Código
- [Qué archivos/componentes se verán afectados]
- [Qué necesita implementarse]
- [Qué necesita modificarse]

**Ejemplo:**
> - Necesitamos instalar `laravel/reverb` vía Composer
> - Crear eventos en `app/Events/` que implementen `ShouldBroadcast`
> - Añadir Laravel Echo en frontend (`npm install laravel-echo pusher-js`)
> - Configurar `.env` con credenciales de Reverb

### Documentación
- [Qué documentos necesitan crearse]
- [Qué documentos necesitan actualizarse]

**Ejemplo:**
> - Crear `docs/modules/optional/REALTIME_SYNC.md`
> - Actualizar `docs/GLOSSARY.md` con términos WebSocket

### Tests
- [Qué tests necesitan añadirse]
- [Qué tests necesitan modificarse]

**Ejemplo:**
> - Crear tests de integración para eventos WebSocket
> - Mockear broadcasting en tests unitarios existentes

### Deployment
- [Cambios en infraestructura]
- [Cambios en proceso de deploy]

**Ejemplo:**
> - Ejecutar `php artisan reverb:start` en producción
> - Configurar supervisor para mantener Reverb activo
> - Abrir puerto 8080 en firewall

---

## Alternativas Rechazadas y Por Qué

### [Nombre de alternativa 1]
**Razón de rechazo:** [Por qué no se eligió]

### [Nombre de alternativa 2]
**Razón de rechazo:** [Por qué no se eligió]

---

## Riesgos

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| [Riesgo 1] | Alta/Media/Baja | Alto/Medio/Bajo | [Cómo mitigarlo] |
| [Riesgo 2] | Alta/Media/Baja | Alto/Medio/Bajo | [Cómo mitigarlo] |

**Ejemplo:**
| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| Reverb no escala bien | Baja | Alto | Monitorear conexiones, migrar a Pusher si supera 800 conexiones |
| Desconexiones frecuentes | Media | Medio | Implementar reconexión automática y buffer de eventos |

---

## Plan de Implementación

1. **[Paso 1]:** [Descripción - Tiempo estimado]
2. **[Paso 2]:** [Descripción - Tiempo estimado]
3. **[Paso 3]:** [Descripción - Tiempo estimado]

**Timeline total:** [X días/semanas]

**Ejemplo:**
1. **Instalar Laravel Reverb:** Composer + configuración .env - 1 hora
2. **Crear eventos broadcast:** PlayerJoined, CanvasDrawEvent, etc. - 2 horas
3. **Configurar frontend:** Laravel Echo + listeners - 3 horas
4. **Testing:** Tests de integración - 4 horas
5. **Documentación:** Crear docs de Realtime Sync - 2 horas

**Timeline total:** 2 días de desarrollo

---

## Métricas de Éxito

[Cómo mediremos si esta decisión fue correcta]

- **[Métrica 1]:** [Objetivo]
- **[Métrica 2]:** [Objetivo]
- **[Métrica 3]:** [Objetivo]

**Ejemplo:**
> - **Latencia WebSocket:** < 500ms en p95
> - **Tasa de desconexión:** < 5%
> - **Conexiones simultáneas:** Soportar al menos 500 sin degradación

---

## Revisión

**Fecha de revisión programada:** [Cuándo revisaremos esta decisión]

**Criterios para reconsiderar:**
- [Condición 1 que haría reconsiderar la decisión]
- [Condición 2]

**Ejemplo:**
> **Fecha de revisión:** 3 meses después del lanzamiento del MVP
>
> **Criterios para reconsiderar:**
> - Si superamos 800 conexiones concurrentes consistentemente
> - Si la latencia supera 1 segundo en más del 10% de los casos
> - Si el costo de mantener Reverb supera el costo de Pusher

---

## Referencias

- **PRD:** [Link al PRD relacionado]
- **Issues:** [Links a issues de GitHub relacionados]
- **Discusiones:** [Links a conversaciones/reuniones]
- **Documentación externa:**
  - [Link a docs de tecnología A]
  - [Link a docs de tecnología B]
- **Código relacionado:**
  - [Link a commits/PRs]

**Ejemplo:**
> - **PRD:** [`tasks/0001-prd-plataforma-juegos-sociales.md`](../../tasks/0001-prd-plataforma-juegos-sociales.md#fr-025)
> - **Laravel Reverb Docs:** https://reverb.laravel.com/
> - **Comparativa Pusher vs Reverb:** https://blog.example.com/pusher-vs-reverb

---

## Notas Adicionales

[Cualquier información adicional relevante que no encaje en las secciones anteriores]

**Ejemplo:**
> Esta decisión fue tomada después de hacer una prueba de concepto (PoC) de 2 días donde implementamos tanto Reverb como Pusher. Los resultados del PoC mostraron que Reverb cumplía todos nuestros requisitos para el MVP.

---

## Historial de Cambios

| Fecha | Cambio | Autor |
|-------|--------|-------|
| YYYY-MM-DD | Creación del ADR | [Nombre] |
| YYYY-MM-DD | Actualización: [descripción] | [Nombre] |
| YYYY-MM-DD | Estado cambiado a Aceptado | [Nombre] |

---

**Mantenido por:** Todo el equipo de desarrollo
**Plantilla version:** 1.0
