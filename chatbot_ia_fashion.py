from __future__ import annotations

from dataclasses import dataclass, field, asdict
from datetime import datetime
from typing import Dict, List, Optional

from flask import Flask, jsonify, request

app = Flask(__name__)

TARIFA_HORA_USD = 50

SERVICIOS: Dict[str, Dict[str, object]] = {
    "ia_agentica_multiagente": {
        "nombre": "IA Agéntica y Multiagente",
        "descripcion": "Diseño de agentes colaborativos para atención, orquestación y toma de decisiones.",
        "base_horas": {"diseno": 35, "implementacion": 80, "pruebas": 24, "puesta_marcha": 16},
    },
    "automatizacion_rpa_ia": {
        "nombre": "Automatización RPA + IA",
        "descripcion": "Automatización de procesos operativos con bots y reglas asistidas por IA.",
        "base_horas": {"diseno": 30, "implementacion": 72, "pruebas": 30, "puesta_marcha": 18},
    },
    "nlp_analisis_texto": {
        "nombre": "NLP y Análisis de Texto",
        "descripcion": "Clasificación, extracción de insights y análisis de sentimiento de reseñas y tickets.",
        "base_horas": {"diseno": 24, "implementacion": 56, "pruebas": 20, "puesta_marcha": 12},
    },
    "computer_vision": {
        "nombre": "Computer Vision",
        "descripcion": "Detección, clasificación y control visual para activos digitales o físicos.",
        "base_horas": {"diseno": 28, "implementacion": 68, "pruebas": 26, "puesta_marcha": 14},
    },
    "mlops_gobernanza": {
        "nombre": "ML Ops y Gobernanza",
        "descripcion": "Ciclo de vida de modelos, observabilidad, calidad y compliance.",
        "base_horas": {"diseno": 26, "implementacion": 64, "pruebas": 24, "puesta_marcha": 20},
    },
    "modelos_predictivos": {
        "nombre": "Modelos Predictivos",
        "descripcion": "Forecasting de demanda, churn, propensión de compra y recomendación.",
        "base_horas": {"diseno": 28, "implementacion": 60, "pruebas": 20, "puesta_marcha": 12},
    },
    "chatbots_empresariales": {
        "nombre": "Chatbots Empresariales",
        "descripcion": "Asistentes virtuales omnicanal con integración a CRM, ERP y e-commerce.",
        "base_horas": {"diseno": 20, "implementacion": 52, "pruebas": 18, "puesta_marcha": 12},
    },
    "dsaas": {
        "nombre": "Data Science as a Service",
        "descripcion": "Equipos y capacidades de data science bajo demanda para objetivos de negocio.",
        "base_horas": {"diseno": 32, "implementacion": 70, "pruebas": 24, "puesta_marcha": 14},
    },
}

COMPLEJIDAD_FACTOR = {"baja": 0.85, "media": 1.0, "alta": 1.35}

FAQ = {
    "catalogo": "Podemos trabajar con su catálogo de 10,000 productos mediante embeddings y ranking híbrido para recomendaciones personalizadas.",
    "trafico": "Con 50,000 visitas mensuales proponemos arquitectura escalable con caché semántica y monitoreo de latencia.",
    "sentimiento": "Para reseñas, aplicamos NLP para sentimiento, temas y alertas de reputación en tiempo casi real.",
    "inventario": "Para inventario, RPA + IA automatiza conciliación de stock, actualización de ERP y alertas de quiebre.",
}

SERVICIO_KEYWORDS = {
    "ia_agentica_multiagente": ["agente", "multiagente", "agéntica", "agentica"],
    "automatizacion_rpa_ia": ["rpa", "automatización", "automatizacion", "inventario", "bot"],
    "nlp_analisis_texto": ["nlp", "texto", "sentimiento", "reseña", "resena"],
    "computer_vision": ["visión", "vision", "imagen", "foto", "video"],
    "mlops_gobernanza": ["mlops", "gobernanza", "monitor", "compliance"],
    "modelos_predictivos": ["predictivo", "pronóstico", "forecast", "demanda"],
    "chatbots_empresariales": ["chatbot", "asistente", "whatsapp", "webchat"],
    "dsaas": ["data science", "analítica", "analitica", "equipo de datos"],
}


@dataclass
class CotizacionState:
    servicio: Optional[str] = None
    complejidad: Optional[str] = None
    alcance: Optional[str] = None
    volumen_datos: Optional[str] = None
    integraciones: List[str] = field(default_factory=list)
    email: Optional[str] = None


SESSIONS: Dict[str, CotizacionState] = {}


def detectar_intent(texto: str) -> str:
    t = texto.lower()
    if any(p in t for p in ["hola", "buenas", "hello"]):
        return "saludo"
    if any(p in t for p in ["cotiz", "precio", "costo", "presupuesto"]):
        return "solicitar_cotizacion"
    if any(p in t for p in ["servicio", "qué ofrecen", "que ofrecen", "solución", "solucion"]):
        return "consultar_servicios"
    if any(p in t for p in ["pdf", "descargar", "correo", "mail"]):
        return "entrega_cotizacion"
    return "faq"


def detectar_servicio(texto: str) -> Optional[str]:
    t = texto.lower()
    for servicio, keywords in SERVICIO_KEYWORDS.items():
        if any(k in t for k in keywords):
            return servicio
    return None


def detectar_complejidad(texto: str) -> Optional[str]:
    t = texto.lower()
    for c in COMPLEJIDAD_FACTOR:
        if c in t:
            return c
    return None


def parsear_integraciones(texto: str) -> List[str]:
    t = texto.lower()
    candidatas = ["shopify", "woocommerce", "erp", "sap", "hubspot", "salesforce", "whatsapp", "zendesk"]
    return [x for x in candidatas if x in t]


def calcular_cotizacion(servicio_key: str, complejidad: str, integraciones: List[str]) -> Dict[str, object]:
    servicio = SERVICIOS[servicio_key]
    horas = servicio["base_horas"].copy()

    factor = COMPLEJIDAD_FACTOR.get(complejidad, 1.0)
    for etapa in horas:
        horas[etapa] = round(horas[etapa] * factor, 1)

    if integraciones:
        extra_integraciones = round(len(integraciones) * 6 * factor, 1)
        horas["implementacion"] += extra_integraciones
        horas["pruebas"] += round(len(integraciones) * 2 * factor, 1)

    total_horas = round(sum(horas.values()), 1)
    total_usd = round(total_horas * TARIFA_HORA_USD, 2)

    return {
        "servicio": servicio["nombre"],
        "complejidad": complejidad,
        "tarifa_hora_usd": TARIFA_HORA_USD,
        "horas": horas,
        "total_horas": total_horas,
        "total_usd": total_usd,
    }


def siguiente_pregunta(state: CotizacionState) -> Optional[str]:
    if not state.servicio:
        return "¿Qué servicio deseas cotizar? (ej: RPA + IA, NLP, Chatbot empresarial...)"
    if not state.complejidad:
        return "¿Qué complejidad estimas para el proyecto? (baja, media o alta)"
    if not state.alcance:
        return "Cuéntame brevemente el alcance: objetivo principal y resultado esperado."
    if not state.volumen_datos:
        return "¿Qué volumen de datos manejarán? (ej: 10,000 productos, 50,000 visitas/mes)"
    if not state.integraciones:
        return "¿Qué integraciones requieren? (Shopify, ERP, WhatsApp, CRM, etc.)"
    return None


def responder_faq(texto: str) -> str:
    t = texto.lower()
    if "producto" in t or "catálogo" in t or "catalogo" in t:
        return FAQ["catalogo"]
    if "visita" in t or "tráfico" in t or "trafico" in t:
        return FAQ["trafico"]
    if "sentimiento" in t or "reseña" in t or "resena" in t:
        return FAQ["sentimiento"]
    if "inventario" in t or "rpa" in t:
        return FAQ["inventario"]
    return (
        "Puedo ayudarte con IA Agéntica, RPA + IA, NLP, Computer Vision, ML Ops, "
        "modelos predictivos, chatbots empresariales y Data Science as a Service. "
        "Si deseas, empezamos una cotización ahora mismo."
    )


@app.post("/api/chat")
def chat():
    payload = request.json or {}
    session_id = payload.get("session_id", "demo")
    mensaje = payload.get("message", "")
    state = SESSIONS.setdefault(session_id, CotizacionState())

    intent = detectar_intent(mensaje)
    servicio = detectar_servicio(mensaje)
    complejidad = detectar_complejidad(mensaje)
    integraciones = parsear_integraciones(mensaje)

    if servicio:
        state.servicio = servicio
    if complejidad:
        state.complejidad = complejidad
    if integraciones:
        state.integraciones = list(set(state.integraciones + integraciones))

    if "@" in mensaje and "." in mensaje:
        state.email = mensaje.strip()

    msg = mensaje.lower()
    if any(p in msg for p in ["alcance", "quiero", "objetivo", "necesito"]) and not state.alcance:
        state.alcance = mensaje
    if any(p in msg for p in ["productos", "visitas", "datos", "volumen"]) and not state.volumen_datos:
        state.volumen_datos = mensaje

    if intent == "saludo":
        respuesta = (
            "¡Hola! Soy tu asesor virtual de IA para e-commerce de moda. "
            "Puedo resolver dudas técnicas y preparar una cotización personalizada."
        )
        pregunta = siguiente_pregunta(state)
        if pregunta:
            respuesta += " " + pregunta
        return jsonify({"reply": respuesta, "state": asdict(state)})

    if intent == "consultar_servicios":
        servicios_texto = "\n".join([f"- {v['nombre']}: {v['descripcion']}" for v in SERVICIOS.values()])
        return jsonify({
            "reply": f"Estos son los servicios que cubro:\n{servicios_texto}\n\n¿Quieres cotizar alguno?",
            "state": asdict(state),
        })

    if intent in {"solicitar_cotizacion", "entrega_cotizacion"}:
        faltante = siguiente_pregunta(state)
        if faltante:
            return jsonify({"reply": f"Perfecto, avancemos con tu cotización. {faltante}", "state": asdict(state)})

        cotizacion = calcular_cotizacion(state.servicio, state.complejidad, state.integraciones)
        resumen = (
            f"Cotización preliminar para {cotizacion['servicio']} ({cotizacion['complejidad']}):\n"
            f"- Diseño: {cotizacion['horas']['diseno']} h\n"
            f"- Implementación: {cotizacion['horas']['implementacion']} h\n"
            f"- Pruebas: {cotizacion['horas']['pruebas']} h\n"
            f"- Puesta en marcha: {cotizacion['horas']['puesta_marcha']} h\n"
            f"Total: {cotizacion['total_horas']} h x ${TARIFA_HORA_USD}/h = ${cotizacion['total_usd']} USD\n"
            "¿Deseas que la enviemos por correo o generar PDF?"
        )
        return jsonify({"reply": resumen, "quote": cotizacion, "state": asdict(state)})

    return jsonify({"reply": responder_faq(mensaje), "state": asdict(state)})


@app.post("/api/cotizacion/enviar-email")
def enviar_email_simulado():
    payload = request.json or {}
    session_id = payload.get("session_id", "demo")
    state = SESSIONS.get(session_id)
    if not state or not state.email:
        return jsonify({"ok": False, "message": "Necesito un correo válido para enviar la cotización."}), 400

    return jsonify({
        "ok": True,
        "message": f"Cotización enviada (simulada) a {state.email} el {datetime.utcnow().isoformat()}Z",
    })


@app.get("/api/cotizacion/pdf")
def descargar_pdf_simulado():
    session_id = request.args.get("session_id", "demo")
    state = SESSIONS.get(session_id)
    if not state or not (state.servicio and state.complejidad):
        return jsonify({"ok": False, "message": "Primero debes completar una cotización."}), 400

    cotizacion = calcular_cotizacion(state.servicio, state.complejidad, state.integraciones)
    return jsonify({
        "ok": True,
        "pdf_url": f"/descargas/cotizacion-{session_id}.pdf",
        "simulado": True,
        "quote": cotizacion,
    })


if __name__ == "__main__":
    app.run(debug=True, port=5050)
