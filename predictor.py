from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
import uvicorn

app = FastAPI(title="VisionFlow AI Forecaster")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.get("/api/trends")
async def get_trends():
    """
    Simulated AI Prediction Endpoint.
    FastAPI automatically converts this standard Python dictionary into perfectly formatted JSON.
    """
    return {
        "status": "success",
        "target_month": "April 2026",
        "predictions": [
            {"shape": "Aviator", "trend_score": 88, "growth": "+15%"},
            {"shape": "Round", "trend_score": 72, "growth": "+5%"},
            {"shape": "Square", "trend_score": 55, "growth": "-2%"},
            {"shape": "Cat-Eye", "trend_score": 40, "growth": "-8%"}
        ],
        "ai_insight": "System Alert: Aviator frames are showing a 15% projected growth curve ahead of the summer season. Recommend increasing inventory stock for 'Aviator' and 'Metal' materials by 20% to prevent stockouts."
    }

if __name__ == "__main__":
    print("🚀 VisionFlow FastAPI Microservice booting up...")
    print("📡 Listening for frontend requests on http://127.0.0.1:8000/api/trends")
    uvicorn.run("predictor:app", host="127.0.0.1", port=8000, reload=True)