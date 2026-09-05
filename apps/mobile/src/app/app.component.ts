import { Component, OnInit, inject } from '@angular/core';
import { IonApp, IonRouterOutlet } from '@ionic/angular/standalone';
import { addIcons } from 'ionicons';
import { BrandingService } from './core/branding.service';
import {
  home, homeOutline, library, libraryOutline, ribbon, ribbonOutline,
  person, personOutline, bookOutline, timeOutline, chevronForward,
  playCircle, playCircleOutline, checkmarkCircle, checkmarkCircleOutline,
  search, logOutOutline, schoolOutline, trophyOutline, flameOutline,
  calendarOutline, arrowForward, lockClosedOutline, mailOutline,
  documentTextOutline, ellipseOutline, star, alertCircleOutline, refresh,
  hourglassOutline, playOutline, listOutline, ribbonSharp, cloudOfflineOutline,
  chevronForwardOutline, briefcaseOutline, businessOutline, cellularOutline,
  helpCircleOutline, alertCircle, warning, informationCircle, close,
  eyeOutline, eyeOffOutline, closeCircle, searchOutline, chevronDown,
  checkmark, pricetagOutline, play, videocamOutline, imageOutline,
  documentOutline, musicalNotesOutline, folderOpenOutline, medalOutline,
  trailSignOutline, layersOutline, lockClosed, clipboardOutline,
  shieldCheckmarkOutline, desktopOutline, cameraOutline, createOutline,
  colorPaletteOutline, informationCircleOutline, phonePortraitOutline,
  sunnyOutline, moonOutline, callOutline, keyOutline,
} from 'ionicons/icons';
import { initNative } from './core/native';
import { ThemeService } from './core/theme.service';
import { ToastHostComponent } from './components/toast/toast-host.component';

addIcons({
  home, homeOutline, library, libraryOutline, ribbon, ribbonOutline,
  person, personOutline, bookOutline, timeOutline, chevronForward,
  playCircle, playCircleOutline, checkmarkCircle, checkmarkCircleOutline,
  search, logOutOutline, schoolOutline, trophyOutline, flameOutline,
  calendarOutline, arrowForward, lockClosedOutline, mailOutline,
  documentTextOutline, ellipseOutline, star, alertCircleOutline, refresh,
  hourglassOutline, playOutline, listOutline, ribbonSharp, cloudOfflineOutline,
  chevronForwardOutline, briefcaseOutline, businessOutline, cellularOutline,
  helpCircleOutline, alertCircle, warning, informationCircle, close,
  eyeOutline, eyeOffOutline, closeCircle, searchOutline, chevronDown,
  checkmark, pricetagOutline, play, videocamOutline, imageOutline,
  documentOutline, musicalNotesOutline, folderOpenOutline, medalOutline,
  trailSignOutline, layersOutline, lockClosed, clipboardOutline,
  shieldCheckmarkOutline, desktopOutline, cameraOutline, createOutline,
  colorPaletteOutline, informationCircleOutline, phonePortraitOutline,
  sunnyOutline, moonOutline, callOutline, keyOutline,
});

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [IonApp, IonRouterOutlet, ToastHostComponent],
  template: `<ion-app><ion-router-outlet></ion-router-outlet></ion-app><app-toast-host></app-toast-host>`,
})
export class AppComponent implements OnInit {
  // Instantiated here so it starts watching the session and paints the
  // remembered organisation's colours from the very first frame.
  private readonly branding = inject(BrandingService);
  // Instantiated at boot so the remembered theme is stamped on <html>
  // before the first paint, avoiding a flash of the wrong palette.
  private readonly theme = inject(ThemeService);

  ngOnInit(): void {
    void initNative();
  }
}
