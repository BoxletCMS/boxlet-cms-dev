"""
Joins one window's shots into one page (demo-shots.mjs, PLAN.md D-213).

  stitch.py out.jpg total-height piece.png:scrollY [piece.png:scrollY ...]

Each piece was shot with the window scrolled to scrollY; the last may overlap the one before,
because a page cannot scroll past its end, so each is placed at the scroll it reported. The
pieces are deleted once joined.
"""
import os
import sys

import cv2
import numpy as np

out, total, pieces = sys.argv[1], int(sys.argv[2]), sys.argv[3:]
canvas = None
for piece in pieces:
    path, at = piece.rsplit(':', 1)
    image = cv2.imread(path)
    if canvas is None:
        canvas = np.zeros((total, image.shape[1], 3), np.uint8)
    top = int(float(at))
    height = min(image.shape[0], total - top)
    canvas[top:top + height] = image[:height]
    os.remove(path)
cv2.imwrite(out, canvas, [cv2.IMWRITE_JPEG_QUALITY, 82])
