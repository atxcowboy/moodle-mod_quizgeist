# Third-party notices

## qrcode 1.5.4

The locally bundled QR-code renderer includes `qrcode`, licensed under the MIT
License.

Copyright (c) 2012 Ryan Day

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

## dijkstrajs 1.0.3

The QR renderer bundles path-finding functions from `dijkstrajs`.

Dijkstra path-finding functions. Adapted from the Dijkstar Python project.

Copyright (C) 2008  
Wyatt Baldwin <self@wyattbaldwin.com>  
All rights reserved

Licensed under the MIT license.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

## @mediapipe/tasks-vision 0.10.x

Der Bühnen-Check (F13, Addon `quizgeistaddon_buehne`) bündelt die
MediaPipe-Vision-Aufgaben von Google, lizenziert unter der Apache License 2.0.

Ausgeliefert werden ausschließlich **lokale** Dateien; es wird zu keinem
Zeitpunkt ein CDN oder ein anderer fremder Host kontaktiert:

| Datei | Bytes |
|---|---|
| `addon/buehne/thirdparty/mediapipe/pose_landmarker_lite.task` | 5.777.746 |
| `addon/buehne/thirdparty/mediapipe/wasm/vision_wasm_internal.js` | 322.044 |
| `addon/buehne/thirdparty/mediapipe/wasm/vision_wasm_internal.wasm` | 11.153.617 |
| `addon/buehne/thirdparty/mediapipe/wasm/vision_wasm_module_internal.js` | 322.082 |
| `addon/buehne/thirdparty/mediapipe/wasm/vision_wasm_module_internal.wasm` | 11.153.641 |
| `addon/buehne/thirdparty/mediapipe/wasm/vision_wasm_nosimd_internal.js` | 321.847 |
| `addon/buehne/thirdparty/mediapipe/wasm/vision_wasm_nosimd_internal.wasm` | 10.481.398 |
| **Summe** | **39.532.375 (37,70 MiB)** |

Dazu kommt der JavaScript-Anteil der Bibliothek im gebauten Bundle
`addon/buehne/amd/build/app_stage.js`. Die Prüfsummen der sieben Dateien
stehen in `frontend/scripts/copy-assets.mjs` und werden bei jedem Bau sowie im
statischen Tor (`clientNoMediaUpload`) nachgerechnet.

Copyright 2023 The MediaPipe Authors.

Licensed under the Apache License, Version 2.0 (the "License"); you may not use
this file except in compliance with the License. You may obtain a copy of the
License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software distributed
under the License is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR
CONDITIONS OF ANY KIND, either express or implied. See the License for the
specific language governing permissions and limitations under the License.

### Verwendungsumfang

Genau **eine** Aufgabe wird benutzt: `PoseLandmarker` mit `runningMode: 'VIDEO'`,
`numPoses: 1`, `outputSegmentationMasks: false` und dem Rückfall
`delegate: 'GPU'` → `'CPU'` → ohne Pose. Kein `FaceLandmarker`, kein
`HandLandmarker`, keine zweite Aufgabenfamilie.
