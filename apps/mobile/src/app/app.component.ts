import { Component, OnInit } from '@angular/core';
import { IonApp, IonRouterOutlet } from '@ionic/angular/standalone';
import { addIcons } from 'ionicons';
import {
  home, homeOutline, library, libraryOutline, ribbon, ribbonOutline,
  person, personOutline, bookOutline, timeOutline, chevronForward,
  playCircle, playCircleOutline, checkmarkCircle, checkmarkCircleOutline,
  search, logOutOutline, schoolOutline, trophyOutline, flameOutline,
  calendarOutline, arrowForward, lockClosedOutline, mailOutline,
  documentTextOutline, ellipseOutline, star, alertCircleOutline, refresh,
  hourglassOutline, playOutline, listOutline, ribbonSharp, cloudOfflineOutline,
  chevronForwardOutline, briefcaseOutline, businessOutline, cellularOutline,
  helpCircleOutline,
} from 'ionicons/icons';
import { initNative } from './core/native';

addIcons({
  home, homeOutline, library, libraryOutline, ribbon, ribbonOutline,
  person, personOutline, bookOutline, timeOutline, chevronForward,
  playCircle, playCircleOutline, checkmarkCircle, checkmarkCircleOutline,
  search, logOutOutline, schoolOutline, trophyOutline, flameOutline,
  calendarOutline, arrowForward, lockClosedOutline, mailOutline,
  documentTextOutline, ellipseOutline, star, alertCircleOutline, refresh,
  hourglassOutline, playOutline, listOutline, ribbonSharp, cloudOfflineOutline,
  chevronForwardOutline, briefcaseOutline, businessOutline, cellularOutline,
  helpCircleOutline,
});

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [IonApp, IonRouterOutlet],
  template: `<ion-app><ion-router-outlet></ion-router-outlet></ion-app>`,
})
export class AppComponent implements OnInit {
  ngOnInit(): void {
    void initNative();
  }
}
