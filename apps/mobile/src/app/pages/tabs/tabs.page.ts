import { Component } from '@angular/core';
import { IonTabs, IonTabBar, IonTabButton, IonIcon, IonLabel } from '@ionic/angular/standalone';
import { TranslatePipe } from '../../core/translate.pipe';

@Component({
  selector: 'app-tabs',
  standalone: true,
  imports: [IonTabs, IonTabBar, IonTabButton, IonIcon, IonLabel, TranslatePipe],
  template: `
    <ion-tabs>
      <ion-tab-bar slot="bottom">
        <ion-tab-button tab="home">
          <ion-icon name="home-outline"></ion-icon>
          <ion-label>{{ 'nav.home' | translate }}</ion-label>
        </ion-tab-button>
        <ion-tab-button tab="courses">
          <ion-icon name="library-outline"></ion-icon>
          <ion-label>{{ 'nav.courses' | translate }}</ion-label>
        </ion-tab-button>
        <ion-tab-button tab="progress">
          <ion-icon name="ribbon-outline"></ion-icon>
          <ion-label>{{ 'nav.progress' | translate }}</ion-label>
        </ion-tab-button>
        <ion-tab-button tab="profile">
          <ion-icon name="person-outline"></ion-icon>
          <ion-label>{{ 'nav.profile' | translate }}</ion-label>
        </ion-tab-button>
      </ion-tab-bar>
    </ion-tabs>
  `,
})
export class TabsPage {}
