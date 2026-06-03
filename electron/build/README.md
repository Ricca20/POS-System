# Application Icon

Place your Windows application icon here as `icon.ico`.

## Requirements
- Format: `.ico`
- Recommended resolution: 256×256 pixels (multi-resolution ICO preferred)
- The file must be named exactly `icon.ico`

## How to create one
1. Design a 256×256 PNG of your logo
2. Convert it to `.ico` using a tool like https://convertio.co/png-ico/ or ImageMagick:
   ```bash
   magick convert logo.png -define icon:auto-resize=256,128,64,48,32,16 icon.ico
   ```
3. Place the resulting `icon.ico` in this directory

electron-builder will automatically embed this icon into the Windows `.exe` and installer.
