"""Faces in pictures, for tools/demo-images (D-207): OpenCV's YuNet detector (FaceDetectorYN).

    faces.py file...   ->   one JSON object per line: {"file": ..., "faces": [[x, y, w, h, score], ...]}

The model is opencv_zoo's face_detection_yunet_2023mar.onnx, at DEMO_FACES_MODEL (default
~/boxlet-build/yunet.onnx). Haar cascades were tried first and found a face in a sheet of
paper; YuNet did not. Only faces of at least 4% of the picture's shorter side, with a score of
0.8 or more, are reported: a face smaller than that is not recognisable.
"""
import json
import os
import sys

import cv2

MODEL = os.environ.get("DEMO_FACES_MODEL", os.path.expanduser("~/boxlet-build/yunet.onnx"))

for path in sys.argv[1:]:
    image = cv2.imread(path)
    if image is None:
        print(json.dumps({"file": path, "error": "unreadable"}))
        continue
    height, width = image.shape[:2]
    scale = min(1.0, 1200.0 / max(width, height))
    small = cv2.resize(image, (int(width * scale), int(height * scale))) if scale < 1 else image
    h, w = small.shape[:2]
    detector = cv2.FaceDetectorYN.create(MODEL, "", (w, h), 0.8, 0.3, 5000)
    _, faces = detector.detect(small)
    least = 0.04 * min(w, h)
    found = []
    for face in faces if faces is not None else []:
        x, y, fw, fh, score = float(face[0]), float(face[1]), float(face[2]), float(face[3]), float(face[14])
        if min(fw, fh) >= least:
            found.append([int(x / scale), int(y / scale), int(fw / scale), int(fh / scale), round(score, 2)])
    print(json.dumps({"file": path, "faces": found}))
