import { POLYMORPHEUS_CONTEXT } from '@taiga-ui/polymorpheus';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { provideNoopAnimations } from '@angular/platform-browser/animations';
import { ComponentFixture, TestBed } from '@angular/core/testing';

import { MediaPreviewDialogComponent } from './media-preview.component';

describe('MediaPreviewDialogComponent', () => {
  let component: MediaPreviewDialogComponent;
  let fixture: ComponentFixture<MediaPreviewDialogComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [MediaPreviewDialogComponent],
      providers: [
        // The component is only ever opened as a dialog, so it needs the
        // dialog context Taiga injects.
        { provide: POLYMORPHEUS_CONTEXT, useValue: { data: { url: '', media_type: 'PDF' }, completeWith: () => {} } },
        // Generated specs never provided these, so none of them could run.
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideNoopAnimations(),
      ],
    })
    .compileComponents();

    fixture = TestBed.createComponent(MediaPreviewDialogComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
