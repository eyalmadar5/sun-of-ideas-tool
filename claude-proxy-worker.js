/**
 * שמש הרעיונות — פרוקסי ל-Claude API
 * -----------------------------------------
 * מה זה עושה:
 * הכלי (בקובץ ה-HTML) שולח בקשות לכתובת של ה-Worker הזה, במקום ישירות ל-Anthropic.
 * ה-Worker מוסיף את מפתח ה-API בסתר (הוא לא נחשף לדפדפן של אף אחד), שולח את
 * הבקשה בפועל ל-Anthropic, ומחזיר את התשובה בדיוק כמו שהייתה.
 *
 * חשוב: הרשימה למטה היא כל המקורות (origins) שמורשים להשתמש בפרוקסי הזה.
 * הוסיפו לכאן רק דומיינים שבאמת בשליטתכם - לא "*", כדי שאף אחד אחר לא
 * ישתמש במפתח ה-API שלכם על חשבונכם.
 */

const ALLOWED_ORIGINS = [
  "https://ideabooster.app",           // האתר עצמו
  "https://eyalmadar5.github.io",      // כאן הכלי בפועל "יושב" (ה-iframe טוען משם)
];

function corsHeaders(request) {
  const origin = request.headers.get("Origin");
  const allowOrigin = ALLOWED_ORIGINS.includes(origin) ? origin : ALLOWED_ORIGINS[0];
  return {
    "Access-Control-Allow-Origin": allowOrigin,
    "Access-Control-Allow-Methods": "POST, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type, anthropic-version",
    "Vary": "Origin",
  };
}

// אבטחה: הכותרות של corsHeaders() הן רק המלצה לדפדפנים - הן לא חוסמות בפועל
// קריאה ישירה מ-curl/סקריפט/שרת אחר (ל-Origin אין אכיפה מחוץ לדפדפן). הפונקציה
// הזו כן חוסמת בצד השרת: אם יש כותרת Origin ואינה ברשימה המורשית - דוחים.
// (בקשות בלי Origin בכלל, כמו מ-curl גולמי, עדיין עוברות כרגע - זה מצמצם
// ניצול לרעה אבל לא מחליף אימות אמיתי; ראו ההמלצה המורחבת שניתנה בנפרד).
function isOriginAllowed(request) {
  const origin = request.headers.get("Origin");
  if (!origin) return true; // כלי בדיקה/סקריפטים פנימיים לרוב לא שולחים Origin בכלל
  return ALLOWED_ORIGINS.includes(origin);
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (request.method === "OPTIONS") {
      return new Response(null, { headers: corsHeaders(request) });
    }

    if (!isOriginAllowed(request)) {
      return jsonResponse(request, { error: { message: "Origin not allowed" } }, 403);
    }

    if (url.pathname === "/transcribe") {
      return handleTranscribe(request, env);
    }

    if (request.method !== "POST") {
      return jsonResponse(request, { error: { message: "Only POST is supported" } }, 405);
    }

    if (!env.ANTHROPIC_API_KEY) {
      return jsonResponse(
        request,
        { error: { message: "Server misconfigured: ANTHROPIC_API_KEY secret is not set" } },
        500
      );
    }

    let incomingBody;
    try {
      incomingBody = await request.json();
    } catch (e) {
      return jsonResponse(request, { error: { message: "Invalid JSON body" } }, 400);
    }

    const MAX_TOKENS_CEILING = 4000;
    if (incomingBody.max_tokens && incomingBody.max_tokens > MAX_TOKENS_CEILING) {
      incomingBody.max_tokens = MAX_TOKENS_CEILING;
    }

    let upstreamResponse;
    try {
      upstreamResponse = await fetch("https://api.anthropic.com/v1/messages", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "x-api-key": env.ANTHROPIC_API_KEY,
          "anthropic-version": "2023-06-01",
        },
        body: JSON.stringify(incomingBody),
      });
    } catch (e) {
      return jsonResponse(request, { error: { message: "Upstream request failed: " + e.message } }, 502);
    }

    const responseText = await upstreamResponse.text();
    return new Response(responseText, {
      status: upstreamResponse.status,
      headers: {
        "Content-Type": "application/json",
        ...corsHeaders(request),
      },
    });
  },
};

/**
 * /transcribe — מקבל קובץ אודיו/וידאו מהכלי, ושולח אותו ל-Whisper של OpenAI
 * לתמלול מדויק (כולל תזמון לכל קטע) - הרבה יותר אמין מזיהוי דיבור חי בדפדפן,
 * שלא תמיד עובד בתוך iframe ממקור אחר.
 */
async function handleTranscribe(request, env) {
  if (request.method !== "POST") {
    return jsonResponse(request, { error: { message: "Only POST is supported" } }, 405);
  }
  if (!env.OPENAI_API_KEY) {
    return jsonResponse(
      request,
      { error: { message: "Server misconfigured: OPENAI_API_KEY secret is not set" } },
      500
    );
  }

  let incomingForm;
  try {
    incomingForm = await request.formData();
  } catch (e) {
    return jsonResponse(request, { error: { message: "Invalid form data" } }, 400);
  }

  const audioFile = incomingForm.get("file");
  if (!audioFile) {
    return jsonResponse(request, { error: { message: "Missing 'file' field" } }, 400);
  }
  const MAX_AUDIO_BYTES = 30 * 1024 * 1024; // 30MB - תואם לוידאו טלפרומפטר סביר, בלי לתת פתח לניצול לרעה
  if (audioFile.size > MAX_AUDIO_BYTES) {
    return jsonResponse(request, { error: { message: "File too large (max 30MB)" } }, 413);
  }
  const lang = incomingForm.get("language") || "he"; // ברירת מחדל לעברית לתאימות לאחור

  const openaiForm = new FormData();
  openaiForm.append("file", audioFile, "audio.webm");
  openaiForm.append("model", "whisper-1");
  openaiForm.append("response_format", "verbose_json");
  openaiForm.append("language", lang);
  openaiForm.append("timestamp_granularities[]", "word");
  openaiForm.append("timestamp_granularities[]", "segment");

  let upstreamResponse;
  try {
    upstreamResponse = await fetch("https://api.openai.com/v1/audio/transcriptions", {
      method: "POST",
      headers: { "Authorization": "Bearer " + env.OPENAI_API_KEY },
      body: openaiForm,
    });
  } catch (e) {
    return jsonResponse(request, { error: { message: "Upstream request failed: " + e.message } }, 502);
  }

  const responseText = await upstreamResponse.text();
  return new Response(responseText, {
    status: upstreamResponse.status,
    headers: { "Content-Type": "application/json", ...corsHeaders(request) },
  });
}

function jsonResponse(request, obj, status) {
  return new Response(JSON.stringify(obj), {
    status: status || 200,
    headers: { "Content-Type": "application/json", ...corsHeaders(request) },
  });
}
