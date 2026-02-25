# Agente virtual conversacional para e-commerce de moda

Este documento describe la arquitectura, la lógica conversacional y la implementación del chatbot para una tienda de moda con:

- Catálogo de **10,000 productos**.
- **50,000 visitas mensuales**.
- Objetivo de mejorar experiencia de cliente, recomendaciones con IA, análisis de sentimiento y automatización de inventario.

## 1) Contexto del proyecto analizado

El asistente está orientado a dos grandes objetivos:

1. **Atención y preventa técnica/comercial** para servicios de IA.
2. **Cotización guiada** con cálculo de tiempos y costos por etapa.

Puntos clave del caso:

- Recomendaciones personalizadas (chatbots empresariales + modelos predictivos + IA agéntica).
- Análisis de sentimiento de reseñas (NLP y análisis de texto).
- Automatización de inventario (RPA + IA).
- Escalabilidad por volumen de catálogo/tráfico.

## 2) Intents, entidades y flujo de diálogo

### Intents principales

- `saludo`
- `consultar_servicios`
- `solicitar_cotizacion`
- `entrega_cotizacion` (enviar por correo o descargar PDF)
- `faq`

### Entidades principales

- `servicio`: uno de los 8 servicios habilitados.
- `complejidad`: `baja`, `media`, `alta`.
- `alcance`: objetivo y resultado esperado.
- `volumen_datos`: productos, visitas, registros históricos, etc.
- `integraciones`: Shopify, ERP, CRM, WhatsApp, etc.
- `email`: correo del cliente para envío simulado.

### Slots requeridos para cotizar

1. Servicio
2. Complejidad
3. Alcance
4. Volumen de datos
5. Integraciones

Cuando los slots están completos, el bot entrega cotización preliminar y ofrece:

- **Enviar cotización por correo** (simulado)
- **Generar URL de PDF** (simulado)

## 3) Modelo de estimación y costos

- Tarifa base: **USD 50/hora**.
- Cada servicio tiene horas base por etapa:
  - Diseño
  - Implementación
  - Pruebas
  - Puesta en marcha
- Ajuste por complejidad:
  - Baja: `0.85`
  - Media: `1.00`
  - Alta: `1.35`
- Ajuste por integraciones:
  - +6h implementación por integración
  - +2h pruebas por integración
  - Ambas multiplicadas por factor de complejidad

## 4) Ejemplo de conversación

**Usuario:** Hola

**Bot:** ¡Hola! Soy tu asesor virtual de IA para e-commerce de moda. Puedo resolver dudas técnicas y preparar una cotización personalizada. ¿Qué servicio deseas cotizar?

**Usuario:** Necesito automatización de inventario con RPA y también análisis de sentimiento de reseñas.

**Bot:** Perfecto. Detecto RPA + IA y NLP. Empecemos por un primer alcance: ¿qué servicio deseas priorizar para esta cotización?

**Usuario:** Prioricemos RPA + IA. Complejidad alta.

**Bot:** Excelente. Cuéntame el alcance del proceso a automatizar.

**Usuario:** Conciliar stock entre Shopify y ERP, y alertar quiebres de inventario.

**Bot:** ¿Qué volumen de datos manejarán?

**Usuario:** 10,000 productos y 50,000 visitas mensuales.

**Bot:** ¿Qué integraciones requieren?

**Usuario:** Shopify, ERP y WhatsApp.

**Bot:** Cotización preliminar: Diseño X h, Implementación Y h, Pruebas Z h, Puesta en marcha W h. Total: N horas = M USD. ¿Deseas envío por correo o PDF?

## 5) Código del agente

Archivo principal: `chatbot_ia_fashion.py`.

Incluye:

- API `/api/chat` para conversación.
- API `/api/cotizacion/enviar-email` (simulado).
- API `/api/cotizacion/pdf` (simulado).
- Detección simple de intents y entidades por palabras clave.
- Estado de sesión y cálculo de cotización.

## 6) Despliegue rápido

### Requisitos

- Python 3.10+
- pip

### Pasos

```bash
python -m venv .venv
source .venv/bin/activate
pip install flask
python chatbot_ia_fashion.py
```

Servidor en: `http://localhost:5050`

### Prueba por cURL

```bash
curl -X POST http://localhost:5050/api/chat \
  -H "Content-Type: application/json" \
  -d '{"session_id":"demo1","message":"Quiero cotizar RPA para inventario en complejidad alta"}'
```

## 7) Integración como widget web (ejemplo)

```html
<div id="chatbot-box"></div>
<script>
  async function enviarMensaje(message) {
    const resp = await fetch("http://localhost:5050/api/chat", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ session_id: "web-user-1", message })
    });
    return resp.json();
  }

  // Ejemplo básico:
  enviarMensaje("Hola, quiero cotizar chatbot empresarial").then(console.log);
</script>
```

> Recomendación: agregar autenticación, persistencia en DB, guardrails de prompts y trazabilidad para uso productivo.
