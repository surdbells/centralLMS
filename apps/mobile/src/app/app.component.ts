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
  documentOutline, musicalNotesOutline, folderOpenOutline,
} from 'ionicons/icons';
import { initNative } from './core/native';
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
  documentOutline, musicalNotesOutline, folderOpenOutline,
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

  ngOnInit(): void {
    void initNative();
  }
}
